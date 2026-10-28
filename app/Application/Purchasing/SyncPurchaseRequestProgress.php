<?php

namespace App\Application\Purchasing;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\WatchStatus;
use App\Integration\Sap\Documents\GoodsReceiptGateway;
use App\Integration\Sap\Documents\PurchaseOrderGateway;
use App\Integration\Sap\Documents\PurchaseQuotationGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\SapDocumentRelation;
use App\Models\SapWatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

class SyncPurchaseRequestProgress
{
    public function __construct(
        private readonly SapCompanyContext $companies,
        private readonly PurchaseQuotationGateway $purchaseQuotations,
        private readonly PurchaseOrderGateway $purchaseOrders,
        private readonly GoodsReceiptGateway $goodsReceipts,
    ) {}

    /**
     * @return array{
     *     quotation_found: bool,
     *     quotation_count: int,
     *     quotation_doc_nums: list<string>,
     *     purchase_order_found: bool,
     *     purchase_order_count: int,
     *     purchase_order_doc_nums: list<string>,
     *     goods_receipt_found: bool,
     *     goods_receipt_count: int,
     *     goods_receipt_doc_nums: list<string>
     * }
     */
    public function handle(SapDocument $purchaseRequest): array
    {
        $this->ensureSyncable($purchaseRequest);
        $watcher = $purchaseRequest->watcher()->firstOrCreate([], [
            'watch_status' => WatchStatus::Active,
            'next_poll_at' => now(),
        ]);

        try {
            $quotationDocuments = $this->quotationDocuments($purchaseRequest);

            if ($quotationDocuments->isEmpty()) {
                $this->markQuotationNotFound($purchaseRequest, $watcher);

                return $this->result();
            }

            $purchaseOrderDocuments = $quotationDocuments
                ->flatMap(fn (SapDocument $quotation): Collection => $this->purchaseOrderDocuments($quotation))
                ->unique('id')
                ->values();

            if ($purchaseOrderDocuments->isEmpty()) {
                $this->markWaitingForPurchaseOrder($purchaseRequest, $watcher, $quotationDocuments);

                return $this->result($quotationDocuments);
            }

            $goodsReceiptDocuments = $purchaseOrderDocuments
                ->flatMap(fn (SapDocument $purchaseOrder): Collection => $this->goodsReceiptDocuments($purchaseOrder))
                ->unique('id')
                ->values();

            if ($goodsReceiptDocuments->isNotEmpty()) {
                $this->markGoodsReceiptFound(
                    $purchaseRequest,
                    $watcher,
                    $quotationDocuments,
                    $purchaseOrderDocuments,
                    $goodsReceiptDocuments,
                );

                return $this->result($quotationDocuments, $purchaseOrderDocuments, $goodsReceiptDocuments);
            }

            $this->markPurchaseOrderFound($purchaseRequest, $watcher, $quotationDocuments, $purchaseOrderDocuments);

            return $this->result($quotationDocuments, $purchaseOrderDocuments);
        } catch (Throwable $exception) {
            $this->markFailed($watcher, $exception);

            throw $exception;
        }
    }

    /** @return Collection<int, SapDocument> */
    private function quotationDocuments(SapDocument $purchaseRequest): Collection
    {
        $existing = $this->activeRelatedDocuments($purchaseRequest, 'PURCHASE_QUOTATION');

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $quotations = collect($this->purchaseQuotations->findByPurchaseRequest((int) $purchaseRequest->sap_doc_entry))
            ->reject(fn (array $quotation): bool => $this->isCancelled($quotation['document']['Cancelled'] ?? null))
            ->values();

        return $this->persistRelatedDocuments(
            parent: $purchaseRequest,
            documents: $quotations,
            sapEntity: 'PurchaseQuotations',
            documentType: 'PURCHASE_QUOTATION',
            relationType: 'PURCHASE_QUOTATION',
            baseType: (int) config('sap.object_types.purchase_request'),
        );
    }

    /** @return Collection<int, SapDocument> */
    private function purchaseOrderDocuments(SapDocument $purchaseQuotation): Collection
    {
        $existing = $this->activeRelatedDocuments($purchaseQuotation, 'PURCHASE_ORDER');

        if ($existing->isNotEmpty()) {
            return $existing
                ->map(fn (SapDocument $purchaseOrder): SapDocument => $this->normalizeExistingPurchaseOrder($purchaseQuotation, $purchaseOrder))
                ->values();
        }

        $purchaseOrders = collect($this->purchaseOrders->findByPurchaseQuotation((int) $purchaseQuotation->sap_doc_entry))
            ->reject(fn (array $purchaseOrder): bool => $this->isCancelled($purchaseOrder['document']['Cancelled'] ?? null))
            ->values();

        return $this->persistRelatedDocuments(
            parent: $purchaseQuotation,
            documents: $purchaseOrders,
            sapEntity: 'PurchaseOrders',
            documentType: 'PURCHASE_ORDER',
            relationType: 'PURCHASE_ORDER',
            baseType: (int) config('sap.object_types.purchase_quotation'),
        );
    }

    /** @return Collection<int, SapDocument> */
    private function goodsReceiptDocuments(SapDocument $purchaseOrder): Collection
    {
        $existing = $this->activeRelatedDocuments($purchaseOrder, 'GOODS_RECEIPT_PO');

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $goodsReceipts = collect($this->goodsReceipts->findByPurchaseOrder((int) $purchaseOrder->sap_doc_entry))
            ->reject(fn (array $goodsReceipt): bool => $this->isCancelled($goodsReceipt['document']['Cancelled'] ?? null))
            ->values();

        return $this->persistRelatedDocuments(
            parent: $purchaseOrder,
            documents: $goodsReceipts,
            sapEntity: 'PurchaseDeliveryNotes',
            documentType: 'GOODS_RECEIPT_PO',
            relationType: 'GOODS_RECEIPT_PO',
            baseType: (int) config('sap.object_types.purchase_order', 22),
        );
    }

    /** @return Collection<int, SapDocument> */
    private function activeRelatedDocuments(SapDocument $parent, string $relationType): Collection
    {
        return $parent->childRelations()
            ->where('relation_type', $relationType)
            ->with('childDocument')
            ->get()
            ->map(fn (SapDocumentRelation $relation): ?SapDocument => $relation->childDocument)
            ->filter(fn (?SapDocument $document): bool => $document !== null
                && $document->sap_business_status !== BusinessStatus::Cancelled)
            ->values();
    }

    /**
     * @param  Collection<int, array{document: array<string, mixed>, lines: list<array<string, mixed>>}>  $documents
     * @return Collection<int, SapDocument>
     */
    private function persistRelatedDocuments(
        SapDocument $parent,
        Collection $documents,
        string $sapEntity,
        string $documentType,
        string $relationType,
        int $baseType,
    ): Collection {
        if ($documents->isEmpty()) {
            return collect();
        }

        return DB::transaction(function () use ($parent, $documents, $sapEntity, $documentType, $relationType, $baseType): Collection {
            return $documents->map(function (array $relatedDocument) use ($parent, $sapEntity, $documentType, $relationType, $baseType): SapDocument {
                $document = $relatedDocument['document'];
                $lines = $relatedDocument['lines'];
                $docEntry = $this->requiredDocEntry($document, $documentType);
                $docNum = filled($document['DocNum'] ?? null) ? (string) $document['DocNum'] : null;
                $childDocument = SapDocument::query()->updateOrCreate(
                    [
                        'company_db' => $parent->company_db,
                        'sap_entity' => $sapEntity,
                        'sap_doc_entry' => $docEntry,
                    ],
                    [
                        'pool_id' => $parent->pool_id,
                        'department_id' => $parent->department_id,
                        'document_type' => $documentType,
                        'sap_doc_num' => $docNum,
                        'source' => 'SAP',
                        'fpk_number' => $parent->fpk_number,
                        'sap_business_status' => $this->businessStatus($document),
                        'integration_status' => IntegrationStatus::InSync,
                        'created_from_web' => false,
                        'normalized_data' => $this->relatedDocumentData($parent, $documentType, $document, $lines),
                    ],
                );

                SapDocumentRelation::query()->updateOrCreate(
                    [
                        'parent_document_id' => $parent->id,
                        'child_document_id' => $childDocument->id,
                        'relation_type' => $relationType,
                    ],
                    [
                        'discovered_by' => 'SERVICE_LAYER_BASE_DOCUMENT',
                        'relation_metadata' => [
                            'base_type' => $baseType,
                            'base_entry' => $parent->sap_doc_entry,
                            'line_count' => count($relatedDocument['lines']),
                        ],
                    ],
                );

                return $childDocument;
            })->values();
        });
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function relatedDocumentData(SapDocument $parent, string $documentType, array $document, array $lines): array
    {
        $parentData = $parent->normalized_data ?? [];
        $data = [
            'base_document_type' => $parent->document_type,
            'base_document_entry' => $parent->sap_doc_entry,
            'vendor_code' => $document['CardCode'] ?? null,
            'vendor_name' => $document['CardName'] ?? null,
            'vehicle' => data_get($parentData, 'vehicle', data_get($parentData, 'vehicle_cost_center.center_code')),
            'vehicle_cost_center' => $parentData['vehicle_cost_center'] ?? null,
            'document' => $document,
            'lines' => $lines,
        ];

        if ($documentType === 'PURCHASE_ORDER') {
            $data['progress_status'] = 'READY_FOR_GRPO';
            $data['open_lines'] = count($lines);
        }

        return $data;
    }

    private function normalizeExistingPurchaseOrder(SapDocument $purchaseQuotation, SapDocument $purchaseOrder): SapDocument
    {
        $data = $purchaseOrder->normalized_data ?? [];
        $document = is_array($data['document'] ?? null) ? $data['document'] : [];
        $lines = array_values(array_filter($data['lines'] ?? [], 'is_array'));
        $purchaseRequest = $purchaseQuotation->parentRelations()
            ->where('relation_type', 'PURCHASE_QUOTATION')
            ->with('parentDocument')
            ->first()
            ?->parentDocument;
        $contextData = $purchaseQuotation->normalized_data ?? [];

        if ($purchaseRequest !== null) {
            $contextData = [...($purchaseRequest->normalized_data ?? []), ...$contextData];
        }

        $purchaseOrder->update([
            'normalized_data' => [
                ...$data,
                'vendor_code' => $data['vendor_code'] ?? $document['CardCode'] ?? null,
                'vendor_name' => $data['vendor_name'] ?? $document['CardName'] ?? null,
                'vehicle' => $data['vehicle'] ?? data_get($contextData, 'vehicle', data_get($contextData, 'vehicle_cost_center.center_code')),
                'vehicle_cost_center' => $data['vehicle_cost_center'] ?? $contextData['vehicle_cost_center'] ?? null,
                'progress_status' => $data['progress_status'] ?? 'READY_FOR_GRPO',
                'open_lines' => $data['open_lines'] ?? count($lines),
            ],
        ]);

        return $purchaseOrder->refresh();
    }

    private function ensureSyncable(SapDocument $purchaseRequest): void
    {
        if ($purchaseRequest->document_type !== 'PURCHASE_REQUEST' || ! is_numeric($purchaseRequest->sap_doc_entry)) {
            throw new InvalidArgumentException('Only a posted Purchase Request can be synchronized.');
        }

        if ($purchaseRequest->company_db !== $this->companies->current()) {
            throw new InvalidArgumentException('Purchase Request company database does not match the active SAP company.');
        }
    }

    /** @param array<string, mixed> $document */
    private function requiredDocEntry(array $document, string $documentType): int
    {
        $docEntry = $document['DocEntry'] ?? null;

        if (! is_numeric($docEntry) || (int) $docEntry < 1) {
            throw new UnexpectedValueException("SAP {$documentType} is missing a valid DocEntry.");
        }

        return (int) $docEntry;
    }

    /** @param array<string, mixed> $document */
    private function businessStatus(array $document): BusinessStatus
    {
        if ($this->isCancelled($document['Cancelled'] ?? null)) {
            return BusinessStatus::Cancelled;
        }

        return in_array($document['DocumentStatus'] ?? null, ['bost_Close', 'C'], true)
            ? BusinessStatus::Closed
            : BusinessStatus::Open;
    }

    private function isCancelled(mixed $value): bool
    {
        return in_array(strtoupper((string) $value), ['Y', 'C', 'TYES'], true);
    }

    /**
     * @param  Collection<int, SapDocument>  $quotationDocuments
     * @param  Collection<int, SapDocument>|null  $purchaseOrderDocuments
     * @return array{
     *     quotation_found: bool,
     *     quotation_count: int,
     *     quotation_doc_nums: list<string>,
     *     purchase_order_found: bool,
     *     purchase_order_count: int,
     *     purchase_order_doc_nums: list<string>,
     *     goods_receipt_found: bool,
     *     goods_receipt_count: int,
     *     goods_receipt_doc_nums: list<string>
     * }
     */
    private function result(
        Collection $quotationDocuments = new Collection,
        ?Collection $purchaseOrderDocuments = null,
        ?Collection $goodsReceiptDocuments = null,
    ): array {
        $purchaseOrderDocuments ??= collect();
        $goodsReceiptDocuments ??= collect();

        return [
            'quotation_found' => $quotationDocuments->isNotEmpty(),
            'quotation_count' => $quotationDocuments->count(),
            'quotation_doc_nums' => $this->documentNumbers($quotationDocuments),
            'purchase_order_found' => $purchaseOrderDocuments->isNotEmpty(),
            'purchase_order_count' => $purchaseOrderDocuments->count(),
            'purchase_order_doc_nums' => $this->documentNumbers($purchaseOrderDocuments),
            'goods_receipt_found' => $goodsReceiptDocuments->isNotEmpty(),
            'goods_receipt_count' => $goodsReceiptDocuments->count(),
            'goods_receipt_doc_nums' => $this->documentNumbers($goodsReceiptDocuments),
        ];
    }

    /** @param Collection<int, SapDocument> $documents */
    private function documentNumbers(Collection $documents): array
    {
        return $documents
            ->pluck('sap_doc_num')
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && $docNum !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param Collection<int, SapDocument> $quotationDocuments */
    private function markWaitingForPurchaseOrder(SapDocument $purchaseRequest, SapWatcher $watcher, Collection $quotationDocuments): void
    {
        $normalizedData = $this->quotationProgressData($purchaseRequest, $quotationDocuments);
        $purchaseRequest->update([
            'normalized_data' => [
                ...$normalizedData,
                'progress_status' => 'WAITING_PO',
                'purchase_order_synced_at' => now()->toIso8601String(),
            ],
        ]);
        $watcher->update([
            'watch_status' => WatchStatus::Active,
            'last_fingerprint' => $this->fingerprint('PQ', $quotationDocuments),
            'last_polled_at' => now(),
            'next_poll_at' => now()->addSeconds($this->pollIntervalSeconds()),
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);
    }

    /**
     * @param  Collection<int, SapDocument>  $quotationDocuments
     * @param  Collection<int, SapDocument>  $purchaseOrderDocuments
     */
    private function markPurchaseOrderFound(
        SapDocument $purchaseRequest,
        SapWatcher $watcher,
        Collection $quotationDocuments,
        Collection $purchaseOrderDocuments,
    ): void {
        $purchaseOrderEntries = $purchaseOrderDocuments->pluck('sap_doc_entry')->filter()->sort()->values()->all();
        $purchaseOrderNumbers = $this->documentNumbers($purchaseOrderDocuments);
        $purchaseRequest->update([
            'normalized_data' => [
                ...$this->quotationProgressData($purchaseRequest, $quotationDocuments),
                'progress_status' => 'READY_FOR_GRPO',
                'purchase_order_doc_entry' => $purchaseOrderEntries[0] ?? null,
                'purchase_order_doc_num' => $purchaseOrderNumbers[0] ?? null,
                'purchase_order_doc_entries' => $purchaseOrderEntries,
                'purchase_order_doc_nums' => $purchaseOrderNumbers,
                'purchase_order_synced_at' => now()->toIso8601String(),
            ],
        ]);
        $watcher->update([
            'watch_status' => WatchStatus::Active,
            'last_fingerprint' => $this->fingerprint('PO', $purchaseOrderDocuments),
            'last_polled_at' => now(),
            'next_poll_at' => now()->addSeconds($this->pollIntervalSeconds()),
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);
    }

    /**
     * @param  Collection<int, SapDocument>  $quotationDocuments
     * @param  Collection<int, SapDocument>  $purchaseOrderDocuments
     * @param  Collection<int, SapDocument>  $goodsReceiptDocuments
     */
    private function markGoodsReceiptFound(
        SapDocument $purchaseRequest,
        SapWatcher $watcher,
        Collection $quotationDocuments,
        Collection $purchaseOrderDocuments,
        Collection $goodsReceiptDocuments,
    ): void {
        $purchaseOrderEntries = $purchaseOrderDocuments->pluck('sap_doc_entry')->filter()->sort()->values()->all();
        $purchaseOrderNumbers = $this->documentNumbers($purchaseOrderDocuments);
        $goodsReceiptEntries = $goodsReceiptDocuments->pluck('sap_doc_entry')->filter()->sort()->values()->all();
        $goodsReceiptNumbers = $this->documentNumbers($goodsReceiptDocuments);
        $purchaseRequest->update([
            'normalized_data' => [
                ...$this->quotationProgressData($purchaseRequest, $quotationDocuments),
                'progress_status' => 'FULLY_RECEIVED',
                'purchase_order_doc_entry' => $purchaseOrderEntries[0] ?? null,
                'purchase_order_doc_num' => $purchaseOrderNumbers[0] ?? null,
                'purchase_order_doc_entries' => $purchaseOrderEntries,
                'purchase_order_doc_nums' => $purchaseOrderNumbers,
                'goods_receipt_doc_entry' => $goodsReceiptEntries[0] ?? null,
                'goods_receipt_doc_num' => $goodsReceiptNumbers[0] ?? null,
                'goods_receipt_doc_entries' => $goodsReceiptEntries,
                'goods_receipt_doc_nums' => $goodsReceiptNumbers,
                'goods_receipt_synced_at' => now()->toIso8601String(),
            ],
        ]);
        $watcher->update([
            'watch_status' => WatchStatus::Stopped,
            'last_fingerprint' => $this->fingerprint('GRPO', $goodsReceiptDocuments),
            'last_polled_at' => now(),
            'next_poll_at' => null,
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);
    }

    /** @param Collection<int, SapDocument> $quotationDocuments */
    private function quotationProgressData(SapDocument $purchaseRequest, Collection $quotationDocuments): array
    {
        $quotationEntries = $quotationDocuments->pluck('sap_doc_entry')->filter()->sort()->values()->all();
        $quotationNumbers = $this->documentNumbers($quotationDocuments);

        return [
            ...($purchaseRequest->normalized_data ?? []),
            'purchase_quotation_doc_entry' => $quotationEntries[0] ?? null,
            'purchase_quotation_doc_num' => $quotationNumbers[0] ?? null,
            'purchase_quotation_doc_entries' => $quotationEntries,
            'purchase_quotation_doc_nums' => $quotationNumbers,
            'purchase_quotation_synced_at' => now()->toIso8601String(),
        ];
    }

    /** @param Collection<int, SapDocument> $documents */
    private function fingerprint(string $stage, Collection $documents): string
    {
        $entries = $documents->pluck('sap_doc_entry')->filter()->sort()->values()->all();

        return hash('sha256', json_encode([$stage, $entries], JSON_THROW_ON_ERROR));
    }

    private function markQuotationNotFound(SapDocument $purchaseRequest, SapWatcher $watcher): void
    {
        $normalizedData = $purchaseRequest->normalized_data ?? [];

        if (in_array($normalizedData['progress_status'] ?? null, ['PR_CREATED', 'WAITING_QUOTATION', 'QUOTATION_CREATED', 'WAITING_PO'], true)) {
            $purchaseRequest->update([
                'normalized_data' => [
                    ...$normalizedData,
                    'progress_status' => 'WAITING_QUOTATION',
                    'purchase_quotation_synced_at' => now()->toIso8601String(),
                ],
            ]);
        }

        $watcher->update([
            'watch_status' => WatchStatus::Active,
            'last_polled_at' => now(),
            'next_poll_at' => now()->addSeconds($this->pollIntervalSeconds()),
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);
    }

    private function markFailed(SapWatcher $watcher, Throwable $exception): void
    {
        $failures = $watcher->consecutive_failures + 1;
        $delay = min($this->pollIntervalSeconds() * (2 ** min($failures - 1, 5)), 3600);
        $watcher->update([
            'watch_status' => WatchStatus::Active,
            'last_polled_at' => now(),
            'next_poll_at' => now()->addSeconds($delay),
            'consecutive_failures' => $failures,
            'last_error' => $exception->getMessage(),
        ]);
    }

    private function pollIntervalSeconds(): int
    {
        return max(30, (int) config('sap.watchers.interval_seconds', 120));
    }
}
