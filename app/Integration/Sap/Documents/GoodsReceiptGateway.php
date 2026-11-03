<?php

namespace App\Integration\Sap\Documents;

use UnexpectedValueException;

class GoodsReceiptGateway extends SapDocumentGateway
{
    /**
     * @return list<array{document: array<string, mixed>, lines: list<array<string, mixed>>}>
     */
    public function findByPurchaseOrder(int $purchaseOrderDocEntry): array
    {
        return $this->findByBaseDocument(
            (int) config('sap.object_types.purchase_order', 22),
            $purchaseOrderDocEntry,
        );
    }

    /**
     * @param  list<int>  $purchaseOrderDocEntries
     * @return list<int>
     */
    public function purchaseOrderEntriesWithReceipts(array $purchaseOrderDocEntries): array
    {
        $purchaseOrderDocEntries = collect($purchaseOrderDocEntries)
            ->filter(fn (mixed $docEntry): bool => is_int($docEntry) && $docEntry > 0)
            ->unique()
            ->values();

        if ($purchaseOrderDocEntries->isEmpty()) {
            return [];
        }

        $dollar = '$';
        $entity = 'PurchaseDeliveryNotes';
        $lineEntity = 'PurchaseDeliveryNotes/DocumentLines';
        $baseType = (int) config('sap.object_types.purchase_order', 22);
        $receivedEntries = collect();

        foreach ($purchaseOrderDocEntries->chunk(20) as $chunk) {
            $baseEntryFilter = $chunk
                ->map(fn (int $docEntry): string => "{$lineEntity}/BaseEntry eq {$docEntry}")
                ->implode(' or ');
            $skip = 0;
            $pageSize = 100;

            do {
                $response = $this->client->request('GET', "/{$dollar}crossjoin({$entity},{$lineEntity})", [
                    'headers' => ['Prefer' => "odata.maxpagesize={$pageSize}"],
                    'query' => [
                        "{$dollar}expand" => "{$entity}({$dollar}select=DocEntry,Cancelled),{$lineEntity}({$dollar}select=BaseType,BaseEntry)",
                        "{$dollar}filter" => implode(' and ', [
                            "{$entity}/DocEntry eq {$lineEntity}/DocEntry",
                            "{$entity}/Cancelled eq 'tNO'",
                            "{$lineEntity}/BaseType eq {$baseType}",
                            "({$baseEntryFilter})",
                        ]),
                        "{$dollar}skip" => $skip,
                        "{$dollar}top" => $pageSize,
                    ],
                ]);
                $rows = $response->json('value');

                if (! is_array($rows)) {
                    throw new UnexpectedValueException('SAP Goods Receipt PO response did not contain a value collection.');
                }

                foreach ($rows as $row) {
                    $baseEntry = data_get($row, "{$lineEntity}.BaseEntry");

                    if (is_numeric($baseEntry)) {
                        $receivedEntries->push((int) $baseEntry);
                    }
                }

                $rowCount = count($rows);
                $skip += $rowCount;
            } while ($rowCount === $pageSize);
        }

        return $receivedEntries->unique()->sort()->values()->all();
    }

    protected function endpointKey(): string
    {
        return 'goods_receipts';
    }
}
