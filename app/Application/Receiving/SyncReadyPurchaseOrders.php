<?php

namespace App\Application\Receiving;

use App\Application\Purchasing\PurchaseRequestReceiptProgress;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\PurchaseOrderAuthorization;
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
        private readonly PurchaseRequestReceiptProgress $requestProgress,
    ) {}

    /** @return array{processed: int, excluded_with_grpo: int, held: list<array{doc_num: string, status: string, reason: string}>, skipped: bool} */
    public function handle(User $user): array
    {
        $user->loadMissing('operationalProfile');
        $profile = $user->operationalProfile;
        $warehouseCode = trim((string) $profile?->warehouse_code);

        if ($profile === null || $warehouseCode === '') {
            throw new RuntimeException('User belum memiliki warehouse untuk sinkronisasi Purchase Order.');
        }

        $companyDb = $this->companies->current();
        $seriesNumbers = collect((array) (config("sap.ready_to_receive.series_by_company.{$companyDb}")
            ?? config('sap.ready_to_receive.series', [887, 888])))
            ->map(fn (mixed $series): int => (int) $series)
            ->filter(fn (int $series): bool => $series > 0)
            ->unique()
            ->values()
            ->all();
        $lookbackMonths = max(1, (int) config('sap.ready_to_receive.lookback_months', 2));
        $syncInterval = max(0, (int) config('sap.ready_to_receive.sync_interval_seconds', 120));
        $cacheKey = 'sap:ready-po:last-sync:'.hash('sha256', $companyDb.':'.$warehouseCode.':'.implode(',', $seriesNumbers));

        if ($syncInterval > 0 && Cache::has($cacheKey)) {
            return ['processed' => 0, 'excluded_with_grpo' => 0, 'held' => Cache::get($cacheKey.':held', []), 'skipped' => true];
        }

        $result = Cache::lock($cacheKey.':lock', 60)->block(5, function () use (
            $cacheKey,
            $companyDb,
            $profile,
            $warehouseCode,
            $seriesNumbers,
            $lookbackMonths,
            $syncInterval,
        ): array {
            if ($syncInterval > 0 && Cache::has($cacheKey)) {
                return ['processed' => 0, 'excluded_with_grpo' => 0, 'held' => Cache::get($cacheKey.':held', []), 'skipped' => true];
            }

            $partialReceipts = (bool) config('sap.goods_receipt.partial_receipts_enabled');
            $fromDate = now()->subMonthsNoOverflow($lookbackMonths)->startOfDay();
            $purchaseOrders = collect($this->purchaseOrders->findOpenForWarehouse(
                warehouseCode: $warehouseCode,
                seriesNumbers: $seriesNumbers,
                fromDate: $fromDate,
            ));
            // With partial receipts (TD-05) a Purchase Order stays Ready while SAP reports open quantity, so a
            // receipt no longer removes it from the list.
            $receivedEntries = $partialReceipts
                ? collect()
                : collect($this->goodsReceipts->purchaseOrderEntriesWithReceipts(
                    $purchaseOrders
                        ->pluck('document.DocEntry')
                        ->filter(fn (mixed $docEntry): bool => is_numeric($docEntry))
                        ->map(fn (mixed $docEntry): int => (int) $docEntry)
                        ->values()
                        ->all(),
                ));
            $withoutReceipt = $purchaseOrders
                ->reject(fn (array $purchaseOrder): bool => $receivedEntries->contains((int) data_get($purchaseOrder, 'document.DocEntry')))
                ->values();
            $readyPurchaseOrders = $withoutReceipt
                ->filter(fn (array $purchaseOrder): bool => PurchaseOrderAuthorization::allowsGoodsReceipt(data_get($purchaseOrder, 'document.AuthorizationStatus')))
                ->values();
            $held = $withoutReceipt
                ->reject(fn (array $purchaseOrder): bool => PurchaseOrderAuthorization::allowsGoodsReceipt(data_get($purchaseOrder, 'document.AuthorizationStatus')))
                ->map(fn (array $purchaseOrder): array => [
                    'doc_num' => (string) data_get($purchaseOrder, 'document.DocNum', data_get($purchaseOrder, 'document.DocEntry')),
                    'status' => (string) data_get($purchaseOrder, 'document.AuthorizationStatus', ''),
                    'reason' => (string) PurchaseOrderAuthorization::holdReason(data_get($purchaseOrder, 'document.AuthorizationStatus')),
                ])
                ->values()
                ->all();
            $readyDocEntries = $readyPurchaseOrders
                ->pluck('document.DocEntry')
                ->map(fn (mixed $docEntry): int => (int) $docEntry)
                ->filter(fn (int $docEntry): bool => $docEntry > 0)
                ->values();

            $reopened = [];

            DB::transaction(function () use ($readyPurchaseOrders, $readyDocEntries, $companyDb, $profile, $warehouseCode, $seriesNumbers, $fromDate, $partialReceipts, &$reopened): void {
                foreach ($readyPurchaseOrders as $purchaseOrder) {
                    $persisted = $this->persist(
                        companyDb: $companyDb,
                        poolId: (int) $profile->pool_id,
                        departmentId: (int) $profile->department_id,
                        warehouseCode: $warehouseCode,
                        purchaseOrder: $purchaseOrder,
                        partialReceipts: $partialReceipts,
                    );

                    if ($persisted !== null) {
                        $reopened[] = $persisted;
                    }
                }

                SapDocument::query()
                    ->forCompany($companyDb)
                    ->where('sap_entity', 'PurchaseOrders')
                    ->where('document_type', 'PURCHASE_ORDER')
                    ->where('sap_business_status', BusinessStatus::Open->value)
                    ->whereIn('normalized_data->series', $seriesNumbers)
                    ->where('normalized_data->warehouse_code', $warehouseCode)
                    ->where('normalized_data->document_date', '>=', $fromDate->toDateString())
                    ->whereNotIn('sap_doc_entry', $readyDocEntries->all())
                    ->update(['sap_business_status' => BusinessStatus::Closed]);
            });

            foreach ($reopened as $purchaseOrder) {
                $this->requestProgress->refreshForPurchaseOrder($purchaseOrder);
            }

            Cache::put($cacheKey.':held', $held, now()->addDay());

            if ($syncInterval > 0) {
                Cache::put($cacheKey, true, $syncInterval);
            }

            return [
                'processed' => $readyPurchaseOrders->count(),
                'excluded_with_grpo' => $receivedEntries->count(),
                'held' => $held,
                'skipped' => false,
            ];
        });

        return $result;
    }

    /**
     * Returns the Purchase Order when it was fully received before and SAP reports open quantity again (for
     * example a cancelled receipt), so that the request progress can be recomputed after the transaction.
     *
     * @param  array{document: array<string, mixed>, lines: list<array<string, mixed>>}  $purchaseOrder
     */
    private function persist(
        string $companyDb,
        int $poolId,
        int $departmentId,
        string $warehouseCode,
        array $purchaseOrder,
        bool $partialReceipts = false,
    ): ?SapDocument {
        $document = $purchaseOrder['document'];
        $lines = $purchaseOrder['lines'];
        $docEntry = (int) ($document['DocEntry'] ?? 0);

        if ($docEntry < 1) {
            return null;
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
        $wasFullyReceived = $partialReceipts && ($existingData['fully_received'] ?? false) === true;
        $progressStatus = 'READY_FOR_GRPO';

        if ($partialReceipts) {
            $hasReceipts = $sapDocument->exists
                && $sapDocument->childRelations()->where('relation_type', 'GOODS_RECEIPT_PO')->exists();
            $hasReducedOpenQuantity = collect($normalizedLines)
                ->contains(fn (array $line): bool => $line['quantity'] > $line['open_quantity'] + 0.000001);
            $progressStatus = $hasReceipts || $hasReducedOpenQuantity ? 'PARTIALLY_RECEIVED' : 'READY_FOR_GRPO';
            $existingData['fully_received'] = false;
        }

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
                'series' => (int) ($document['Series'] ?? 0),
                'document_date' => $document['DocDate'] ?? null,
                'vendor_code' => $document['CardCode'] ?? null,
                'vendor_name' => $document['CardName'] ?? null,
                'warehouse_code' => $warehouseCode,
                'progress_status' => $progressStatus,
                'open_lines' => count($normalizedLines),
                'authorization_status' => $document['AuthorizationStatus'] ?? null,
                'ready_for_grpo_synced_at' => now()->toIso8601String(),
                'document' => $document,
                'lines' => $normalizedLines,
            ],
        ])->save();

        return $wasFullyReceived ? $sapDocument : null;
    }
}
