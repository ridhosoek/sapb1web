<?php

namespace App\Integration\Sap\Documents;

use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class PurchaseOrderGateway extends SapDocumentGateway
{
    /**
     * @param  list<int>  $seriesNumbers
     * @return list<array{document: array<string, mixed>, lines: list<array<string, mixed>>}>
     */
    public function findByPurchaseQuotation(int $purchaseQuotationDocEntry): array
    {
        return $this->findByBaseDocument(
            (int) config('sap.object_types.purchase_quotation'),
            $purchaseQuotationDocEntry,
        );
    }

    /**
     * @return list<array{document: array<string, mixed>, lines: list<array<string, mixed>>}>
     */
    public function findOpenForWarehouse(
        string $warehouseCode,
        array $seriesNumbers,
        DateTimeInterface $fromDate,
    ): array {
        $warehouseCode = trim($warehouseCode);
        $seriesNumbers = collect($seriesNumbers)
            ->map(fn (mixed $series): int => (int) $series)
            ->filter(fn (int $series): bool => $series > 0)
            ->unique()
            ->values()
            ->all();

        if ($warehouseCode === '' || $seriesNumbers === []) {
            throw new UnexpectedValueException('SAP warehouse and Purchase Order series must be configured.');
        }

        $dollar = '$';
        $entity = 'PurchaseOrders';
        $lineEntity = 'PurchaseOrders/DocumentLines';
        $pageSize = max(1, min(20, (int) config('sap.ready_to_receive.page_size', 100)));
        $maximumRows = max($pageSize, (int) config('sap.ready_to_receive.maximum_rows', 1000));
        $escapedWarehouse = str_replace("'", "''", $warehouseCode);
        $seriesFilter = collect($seriesNumbers)
            ->map(fn (int $series): string => "{$entity}/Series eq {$series}")
            ->implode(' or ');
        $vehicleCostingField = (string) config('sap.purchase_request.vehicle_costing_field', 'CostingCode3');
        $documents = [];
        $skip = 0;

        do {
            $response = $this->client->request('GET', "/{$dollar}crossjoin({$entity},{$lineEntity})", [
                'headers' => ['Prefer' => "odata.maxpagesize={$pageSize}"],
                'query' => [
                    "{$dollar}expand" => "{$entity}({$dollar}select=DocEntry,DocNum,Series,DocDate,DocDueDate,DocumentStatus,Cancelled,AuthorizationStatus,CardCode,CardName,Comments),{$lineEntity}({$dollar}select=DocEntry,LineNum,LineStatus,ItemCode,ItemDescription,Quantity,RemainingOpenQuantity,WarehouseCode,UoMCode,MeasureUnit,{$vehicleCostingField})",
                    "{$dollar}filter" => implode(' and ', [
                        "{$entity}/DocEntry eq {$lineEntity}/DocEntry",
                        "({$seriesFilter})",
                        "{$entity}/DocDate ge '".$fromDate->format('Y-m-d')."'",
                        "{$entity}/DocumentStatus eq 'bost_Open'",
                        "{$entity}/Cancelled eq 'tNO'",
                        "{$lineEntity}/WarehouseCode eq '{$escapedWarehouse}'",
                        "{$lineEntity}/LineStatus eq 'bost_Open'",
                        "{$lineEntity}/RemainingOpenQuantity gt 0",
                    ]),
                    "{$dollar}orderby" => "{$entity}/DocDate desc,{$entity}/DocEntry desc,{$lineEntity}/LineNum asc",
                    "{$dollar}skip" => $skip,
                    "{$dollar}top" => $pageSize,
                ],
            ]);
            $rows = $response->json('value');

            if (! is_array($rows)) {
                throw new UnexpectedValueException('SAP Purchase Order response did not contain a value collection.');
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $document = is_array($row[$entity] ?? null) ? $row[$entity] : [];
                $line = is_array($row[$lineEntity] ?? null) ? $row[$lineEntity] : [];
                $docEntry = $document['DocEntry'] ?? null;

                if (! is_numeric($docEntry)) {
                    continue;
                }

                $key = (int) $docEntry;
                $documents[$key] ??= ['document' => $document, 'lines' => []];

                if ($line !== []) {
                    $documents[$key]['lines'][] = $line;
                }
            }

            $rowCount = count($rows);
            $skip += $rowCount;
            // A full page, or a next link from SAP when it caps the page below $top, means there is more to read.
            $hasMore = $rowCount > 0 && ($rowCount >= $pageSize || filled(data_get($response->json(), ['odata.nextLink'])));
            $lastDocEntry = $key ?? null;
        } while ($hasMore && $skip < $maximumRows);

        if ($hasMore) {
            // The row cap cut the list inside a Purchase Order: its lines may be incomplete, so it is dropped rather
            // than shown with a wrong open quantity, and the cut is logged because a later PO could be missing.
            if ($lastDocEntry !== null) {
                unset($documents[$lastDocEntry]);
            }

            Log::warning('Ready Purchase Order list reached the row limit; older Purchase Orders were not read.', [
                'maximum_rows' => $maximumRows,
                'warehouse_code' => $warehouseCode,
            ]);
        }

        return array_values($documents);
    }

    protected function endpointKey(): string
    {
        return 'purchase_orders';
    }
}
