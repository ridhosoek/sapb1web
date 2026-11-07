<?php

namespace App\Application\Purchasing;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\WatchStatus;
use App\Models\SapDocument;
use Illuminate\Support\Collection;

/**
 * Derives the Purchase Request receipt progress from every Purchase Order and active Goods Receipt PO of the
 * request (TD-05): PARTIALLY_RECEIVED until every Purchase Order is fully received in SAP, then FULLY_RECEIVED.
 * A cancelled receipt is not counted, so progress can move back when SAP gives the quantity back.
 */
class PurchaseRequestReceiptProgress
{
    public function refreshForPurchaseOrder(SapDocument $purchaseOrder): void
    {
        $purchaseRequest = $purchaseOrder->parentRelations()
            ->where('relation_type', 'PURCHASE_ORDER')
            ->with('parentDocument.parentRelations.parentDocument')
            ->get()
            ->map(fn ($relation) => $relation->parentDocument?->parentRelations
                ->firstWhere('relation_type', 'PURCHASE_QUOTATION')?->parentDocument)
            ->filter()
            ->first();

        if ($purchaseRequest instanceof SapDocument) {
            $this->refresh($purchaseRequest);
        }
    }

    public function refresh(SapDocument $purchaseRequest): void
    {
        $purchaseOrders = $this->purchaseOrders($purchaseRequest);

        if ($purchaseOrders->isEmpty()) {
            return;
        }

        $goodsReceipts = $purchaseOrders
            ->flatMap(fn (SapDocument $purchaseOrder): Collection => $purchaseOrder->childRelations()
                ->where('relation_type', 'GOODS_RECEIPT_PO')
                ->with('childDocument')
                ->get()
                ->map(fn ($relation): ?SapDocument => $relation->childDocument)
                ->filter(fn (?SapDocument $document): bool => $document !== null
                    && $document->sap_business_status !== BusinessStatus::Cancelled))
            ->unique('id')
            ->values();
        $isFullyReceived = $goodsReceipts->isNotEmpty()
            && $purchaseOrders->every(fn (SapDocument $purchaseOrder): bool => data_get($purchaseOrder->normalized_data, 'fully_received') === true);
        $status = match (true) {
            $isFullyReceived => 'FULLY_RECEIVED',
            $goodsReceipts->isNotEmpty() => 'PARTIALLY_RECEIVED',
            default => 'READY_FOR_GRPO',
        };
        $entries = $goodsReceipts->pluck('sap_doc_entry')->filter()->sort()->values()->all();
        $numbers = $goodsReceipts->pluck('sap_doc_num')->filter()->unique()->sort()->values()->all();
        $normalizedData = $purchaseRequest->normalized_data ?? [];
        $normalizedData['progress_status'] = $status;
        $normalizedData['goods_receipt_doc_entry'] = $entries[0] ?? null;
        $normalizedData['goods_receipt_doc_num'] = $numbers[0] ?? null;
        $normalizedData['goods_receipt_doc_entries'] = $entries;
        $normalizedData['goods_receipt_doc_nums'] = $numbers;
        $normalizedData['goods_receipt_synced_at'] = now()->toIso8601String();
        $purchaseRequest->update(['normalized_data' => $normalizedData]);

        $purchaseRequest->watcher()->first()?->update([
            'watch_status' => $isFullyReceived ? WatchStatus::Stopped : WatchStatus::Active,
            'last_fingerprint' => hash('sha256', json_encode(['GRPO', $entries, $isFullyReceived], JSON_THROW_ON_ERROR)),
            'last_polled_at' => now(),
            'next_poll_at' => $isFullyReceived ? null : now()->addSeconds(max(30, (int) config('sap.watchers.interval_seconds', 120))),
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);
    }

    /** @return Collection<int, SapDocument> */
    private function purchaseOrders(SapDocument $purchaseRequest): Collection
    {
        return $purchaseRequest->childRelations()
            ->where('relation_type', 'PURCHASE_QUOTATION')
            ->with('childDocument.childRelations.childDocument')
            ->get()
            ->map(fn ($relation): ?SapDocument => $relation->childDocument)
            ->filter()
            ->flatMap(fn (SapDocument $quotation): Collection => $quotation->childRelations
                ->where('relation_type', 'PURCHASE_ORDER')
                ->map(fn ($relation): ?SapDocument => $relation->childDocument)
                ->filter(fn (?SapDocument $document): bool => $document !== null
                    && $document->sap_business_status !== BusinessStatus::Cancelled))
            ->unique('id')
            ->values();
    }
}
