<?php

namespace App\Application\Receiving;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Integration\Sap\Documents\GoodsReceiptGateway;
use App\Integration\Sap\Documents\PurchaseOrderGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncReadyPurchaseOrders
{
    public function __construct(
        private readonly SapCompanyContext $companies,
        private readonly PurchaseOrderGateway $purchaseOrders,
        private readonly GoodsReceiptGateway $goodsReceipts,
    ) {}

    /** @return array{processed: int, excluded_with_grpo: int, skipped: bool} */
    public function handle(User $user): array
    {
        $user->loadMissing('operationalProfile');
        $profile = $user->operationalProfile;
        $warehouseCode = trim((string) $profile?->warehouse_code);

        if ($profile === null || $warehouseCode === '') {
            throw new RuntimeException('User belum memiliki warehouse untuk sinkronisasi Purchase Order.');
        }

        $companyDb = $this->companies->current();
        $series = (int) config('sap.ready_to_receive.series', 887);
        $lookbackMonths = max(1, (int) config('sap.ready_to_receive.lookback_months', 2));
        $syncInterval = max(0, (int) config('sap.ready_to_receive.sync_interval_seconds', 120));
        $cacheKey = 'sap:ready-po:last-sync:'.hash('sha256', $companyDb.':'.$warehouseCode.':'.$series);

        if ($syncInterval > 0 && Cache::has($cacheKey)) {
            return ['processed' => 0, 'excluded_with_grpo' => 0, 'skipped' => true];
        }

        $result = Cache::lock($cacheKey.':lock', 60)->block(5, function () use (
            $cacheKey,
            $companyDb,
            $profile,
            $warehouseCode,
            $series,
            $lookbackMonths,
            $syncInterval,
        ): array {
            if ($syncInterval > 0 && Cache::has($cacheKey)) {
                return ['processed' => 0, 'excluded_with_grpo' => 0, 'skipped' => true];
            }

            $fromDate = now()->subMonthsNoOverflow($lookbackMonths)->startOfDay();
            $purchaseOrders = collect($this->purchaseOrders->findOpenForWarehouse(
                warehouseCode: $warehouseCode,
                series: $series,
                fromDate: $fromDate,
            ));
            $receivedEntries = collect($this->goodsReceipts->purchaseOrderEntriesWithReceipts(
                $purchaseOrders
                    ->pluck('document.DocEntry')
                    ->filter(fn (mixed $docEntry): bool => is_numeric($docEntry))
                    ->map(fn (mixed $docEntry): int => (int) $docEntry)
                    ->values()
                    ->all(),
            ));
            $readyPurchaseOrders = $purchaseOrders
                ->reject(fn (array $purchaseOrder): bool => $receivedEntries->contains((int) data_get($purchaseOrder, 'document.DocEntry')))
                ->values();
            $readyDocEntries = $readyPurchaseOrders
                ->pluck('document.DocEntry')
                ->map(fn (mixed $docEntry): int => (int) $docEntry)
                ->filter(fn (int $docEntry): bool => $docEntry > 0)
                ->values();

            DB::transaction(function () use ($readyPurchaseOrders, $readyDocEntries, $companyDb, $profile, $warehouseCode, $series, $fromDate): void {
                foreach ($readyPurchaseOrders as $purchaseOrder) {
                    $this->persist(
                        companyDb: $companyDb,
                        poolId: (int) $profile->pool_id,
                        departmentId: (int) $profile->department_id,
                        warehouseCode: $warehouseCode,
                        series: $series,
                        purchaseOrder: $purchaseOrder,
                    );
                }

                SapDocument::query()
                    ->forCompany($companyDb)
                    ->where('sap_entity', 'PurchaseOrders')
                    ->where('document_type', 'PURCHASE_ORDER')
                    ->where('sap_business_status', BusinessStatus::Open->value)
                    ->where('normalized_data->series', $series)
                    ->where('normalized_data->warehouse_code', $warehouseCode)
                    ->where('normalized_data->document_date', '>=', $fromDate->toDateString())
                    ->whereNotIn('sap_doc_entry', $readyDocEntries->all())
                    ->update(['sap_business_status' => BusinessStatus::Closed]);
            });

            if ($syncInterval > 0) {
                Cache::put($cacheKey, true, $syncInterval);
            }

            return [
                'processed' => $readyPurchaseOrders->count(),
                'excluded_with_grpo' => $receivedEntries->count(),
                'skipped' => false,
            ];
        });

        return $result;
    }

    /**
     * @param  array{document: array<string, mixed>, lines: list<array<string, mixed>>}  $purchaseOrder
     */
    private function persist(
        string $companyDb,
        int $poolId,
        int $departmentId,
        string $warehouseCode,
        int $series,
        array $purchaseOrder,
    ): void {
        $document = $purchaseOrder['document'];
        $lines = $purchaseOrder['lines'];
        $docEntry = (int) ($document['DocEntry'] ?? 0);

        if ($docEntry < 1) {
            return;
        }

        $sapDocument = SapDocument::query()->firstOrNew([
            'company_db' => $companyDb,
            'sap_entity' => 'PurchaseOrders',
            'sap_doc_entry' => $docEntry,
        ]);
        $existingData = is_array($sapDocument->normalized_data) ? $sapDocument->normalized_data : [];
        $vehicleCostingField = (string) config('sap.purchase_request.vehicle_costing_field', 'CostingCode3');
        $normalizedLines = collect($lines)->map(function (array $line) use ($vehicleCostingField): array {
            $openQuantity = max(0, (float) ($line['RemainingOpenQuantity'] ?? $line['OpenQuantity'] ?? $line['Quantity'] ?? 0));

            return [
                ...$line,
                'line_num' => (int) ($line['LineNum'] ?? 0),
                'item_code' => (string) ($line['ItemCode'] ?? ''),
                'item_name' => (string) ($line['ItemDescription'] ?? ''),
                'quantity' => (float) ($line['Quantity'] ?? 0),
                'open_quantity' => $openQuantity,
                'warehouse_code' => (string) ($line['WarehouseCode'] ?? ''),
                'vehicle_code' => filled($line[$vehicleCostingField] ?? null)
                    ? (string) $line[$vehicleCostingField]
                    : null,
            ];
        })->values()->all();

        unset($existingData['vehicle'], $existingData['vehicle_cost_center']);
        $sapDocument->fill([
            'pool_id' => $sapDocument->exists ? $sapDocument->pool_id : $poolId,
            'department_id' => $sapDocument->exists ? $sapDocument->department_id : $departmentId,
            'document_type' => 'PURCHASE_ORDER',
            'sap_doc_num' => filled($document['DocNum'] ?? null) ? (string) $document['DocNum'] : null,
            'source' => 'SAP',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'created_from_web' => false,
            'normalized_data' => [
                ...$existingData,
                'series' => $series,
                'document_date' => $document['DocDate'] ?? null,
                'vendor_code' => $document['CardCode'] ?? null,
                'vendor_name' => $document['CardName'] ?? null,
                'warehouse_code' => $warehouseCode,
                'progress_status' => 'READY_FOR_GRPO',
                'open_lines' => count($normalizedLines),
                'ready_for_grpo_synced_at' => now()->toIso8601String(),
                'document' => $document,
                'lines' => $normalizedLines,
            ],
        ])->save();
    }
}
