<?php

namespace App\Integration\Sap\Inventory;

use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

class StockLedgerGateway
{
    public function __construct(private readonly SapServiceLayerClient $client) {}

    /**
     * @return list<array{
     *     date: string,
     *     document_type: string,
     *     document_number: string,
     *     document_entry: int,
     *     reference: string|null,
     *     remarks: string|null,
     *     quantity_in: float,
     *     quantity_out: float,
     *     warehouse_code: string
     * }>
     */
    public function recent(string $itemCode, string $warehouseCode, int $limit = 100): array
    {
        $perSourceLimit = max(10, min($limit, 100));
        $movements = collect([
            ['PurchaseDeliveryNotes', 'GRPO', 'IN'],
            ['InventoryGenEntries', 'Goods Receipt', 'IN'],
            ['DeliveryNotes', 'Delivery', 'OUT'],
            ['InventoryGenExits', 'Goods Issue', 'OUT'],
            ['Returns', 'Sales Return', 'IN'],
            ['PurchaseReturns', 'Goods Return', 'OUT'],
        ])->flatMap(fn (array $source): array => $this->documentMovements(
            entity: $source[0],
            label: $source[1],
            direction: $source[2],
            itemCode: $itemCode,
            warehouseCode: $warehouseCode,
            limit: $perSourceLimit,
        ));

        return $movements
            ->concat($this->transferMovements($itemCode, $warehouseCode, $perSourceLimit))
            ->sortByDesc(fn (array $movement): string => $movement['date'].'|'.str_pad($movement['document_number'], 20, '0', STR_PAD_LEFT))
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function documentMovements(
        string $entity,
        string $label,
        string $direction,
        string $itemCode,
        string $warehouseCode,
        int $limit,
    ): array {
        $dollar = '$';
        $lineEntity = "{$entity}/DocumentLines";
        $response = $this->client->request('GET', "/{$dollar}crossjoin({$entity},{$lineEntity})", [
            'query' => [
                "{$dollar}expand" => "{$entity}({$dollar}select=DocEntry,DocNum,DocDate,Cancelled,Reference2,Comments),{$lineEntity}({$dollar}select=LineNum,ItemCode,Quantity,WarehouseCode)",
                "{$dollar}filter" => "{$entity}/DocEntry eq {$lineEntity}/DocEntry and {$lineEntity}/ItemCode eq '{$this->escape($itemCode)}' and {$lineEntity}/WarehouseCode eq '{$this->escape($warehouseCode)}'",
                "{$dollar}orderby" => "{$entity}/DocDate desc,{$entity}/DocEntry desc",
                "{$dollar}top" => $limit,
            ],
        ]);

        $rows = $response->json('value');

        if (! is_array($rows)) {
            throw new UnexpectedValueException("SAP {$label} ledger response did not contain a value collection.");
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row) use ($entity, $lineEntity, $label, $direction, $warehouseCode): ?array {
                $document = is_array($row[$entity] ?? null) ? $row[$entity] : [];
                $line = is_array($row[$lineEntity] ?? null) ? $row[$lineEntity] : [];

                if ($this->isCancelled($document['Cancelled'] ?? null)) {
                    return null;
                }

                return $this->movement(
                    document: $document,
                    label: $label,
                    direction: $direction,
                    quantity: (float) ($line['Quantity'] ?? 0),
                    warehouseCode: $warehouseCode,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function transferMovements(string $itemCode, string $warehouseCode, int $limit): array
    {
        $dollar = '$';
        $entity = 'StockTransfers';
        $lineEntity = 'StockTransfers/StockTransferLines';
        $response = $this->client->request('GET', "/{$dollar}crossjoin({$entity},{$lineEntity})", [
            'query' => [
                "{$dollar}expand" => "{$entity}({$dollar}select=DocEntry,DocNum,DocDate,Comments),{$lineEntity}({$dollar}select=LineNum,ItemCode,Quantity,WarehouseCode,FromWarehouseCode)",
                "{$dollar}filter" => "{$entity}/DocEntry eq {$lineEntity}/DocEntry and {$lineEntity}/ItemCode eq '{$this->escape($itemCode)}' and ({$lineEntity}/WarehouseCode eq '{$this->escape($warehouseCode)}' or {$lineEntity}/FromWarehouseCode eq '{$this->escape($warehouseCode)}')",
                "{$dollar}orderby" => "{$entity}/DocDate desc,{$entity}/DocEntry desc",
                "{$dollar}top" => $limit,
            ],
        ]);

        $rows = $response->json('value');

        if (! is_array($rows)) {
            throw new UnexpectedValueException('SAP Stock Transfer ledger response did not contain a value collection.');
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row) use ($entity, $lineEntity, $warehouseCode): array {
                $document = is_array($row[$entity] ?? null) ? $row[$entity] : [];
                $line = is_array($row[$lineEntity] ?? null) ? $row[$lineEntity] : [];
                $direction = (string) ($line['FromWarehouseCode'] ?? '') === $warehouseCode ? 'OUT' : 'IN';

                return $this->movement(
                    document: $document,
                    label: $direction === 'IN' ? 'Stock Transfer In' : 'Stock Transfer Out',
                    direction: $direction,
                    quantity: (float) ($line['Quantity'] ?? 0),
                    warehouseCode: $warehouseCode,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function movement(array $document, string $label, string $direction, float $quantity, string $warehouseCode): array
    {
        return [
            'date' => (string) ($document['DocDate'] ?? ''),
            'document_type' => $label,
            'document_number' => (string) ($document['DocNum'] ?? ''),
            'document_entry' => (int) ($document['DocEntry'] ?? 0),
            'reference' => filled($document['Reference2'] ?? null) ? (string) $document['Reference2'] : null,
            'remarks' => filled($document['Comments'] ?? null) ? (string) $document['Comments'] : null,
            'quantity_in' => $direction === 'IN' ? $quantity : 0.0,
            'quantity_out' => $direction === 'OUT' ? $quantity : 0.0,
            'warehouse_code' => $warehouseCode,
        ];
    }

    private function isCancelled(mixed $value): bool
    {
        return in_array(strtoupper((string) $value), ['Y', 'C', 'TYES'], true);
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
