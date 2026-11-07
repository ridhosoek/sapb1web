<?php

namespace App\Application\Receiving;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Models\SapDocument;

/**
 * Applies a live SAP Purchase Order read to the portal copy so that the open quantity per line, the Ready list
 * and the Purchase Request progress all come from SAP and never from local subtraction (TD-05).
 */
class PurchaseOrderReceiptProgress
{
    /**
     * @param  array<string, mixed>  $livePurchaseOrder
     */
    public function apply(SapDocument $purchaseOrder, array $livePurchaseOrder): void
    {
        $vehicleCostingField = (string) config('sap.purchase_request.vehicle_costing_field', 'CostingCode3');
        $liveLines = collect($livePurchaseOrder['DocumentLines'] ?? [])
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values();
        $normalizedLines = $liveLines->map(function (array $line) use ($vehicleCostingField): array {
            $openQuantity = $this->openQuantity($line);

            return [
                ...$line,
                'line_num' => (int) ($line['LineNum'] ?? 0),
                'item_code' => (string) ($line['ItemCode'] ?? ''),
                'item_name' => (string) ($line['ItemDescription'] ?? ''),
                'quantity' => (float) ($line['Quantity'] ?? 0),
                'open_quantity' => $openQuantity,
                'warehouse_code' => (string) ($line['WarehouseCode'] ?? ''),
                'vehicle_code' => filled($line[$vehicleCostingField] ?? null) ? (string) $line[$vehicleCostingField] : null,
            ];
        });
        $openLines = $normalizedLines->filter(fn (array $line): bool => $this->isOpen($line))->values();
        $isCancelled = in_array(strtoupper((string) ($livePurchaseOrder['Cancelled'] ?? 'N')), ['Y', 'C', 'TYES'], true);
        $isFullyReceived = ! $isCancelled && ($this->isClosed($livePurchaseOrder) || $openLines->isEmpty());
        $hasReceipts = $purchaseOrder->childRelations()
            ->where('relation_type', 'GOODS_RECEIPT_PO')
            ->whereHas('childDocument', fn ($query) => $query->where('sap_business_status', '!=', BusinessStatus::Cancelled->value))
            ->exists();
        $hasReducedOpenQuantity = $normalizedLines->contains(
            fn (array $line): bool => $line['quantity'] > $line['open_quantity'] + 0.000001,
        );
        $documentData = $livePurchaseOrder;
        unset($documentData['DocumentLines']);
        $normalizedData = $purchaseOrder->normalized_data ?? [];
        $normalizedData['document'] = $documentData;
        $normalizedData['lines'] = $openLines->all();
        $normalizedData['open_lines'] = $openLines->count();
        $normalizedData['authorization_status'] = $livePurchaseOrder['AuthorizationStatus'] ?? null;
        $normalizedData['fully_received'] = $isFullyReceived;
        $normalizedData['receipt_state_synced_at'] = now()->toIso8601String();
        $normalizedData['progress_status'] = match (true) {
            $isFullyReceived => 'RECEIVED',
            $hasReceipts || $hasReducedOpenQuantity => 'PARTIALLY_RECEIVED',
            default => 'READY_FOR_GRPO',
        };

        $purchaseOrder->update([
            'sap_business_status' => match (true) {
                $isCancelled => BusinessStatus::Cancelled,
                $isFullyReceived => BusinessStatus::Closed,
                default => BusinessStatus::Open,
            },
            'integration_status' => IntegrationStatus::InSync,
            'normalized_data' => $normalizedData,
        ]);
    }

    /** @param array<string, mixed> $line */
    private function isOpen(array $line): bool
    {
        return ($line['LineStatus'] ?? 'bost_Open') !== 'bost_Close' && $line['open_quantity'] > 0.000001;
    }

    /** @param array<string, mixed> $livePurchaseOrder */
    private function isClosed(array $livePurchaseOrder): bool
    {
        return ! in_array($livePurchaseOrder['DocumentStatus'] ?? 'bost_Open', ['O', 'bost_Open'], true);
    }

    /** @param array<string, mixed> $line */
    private function openQuantity(array $line): float
    {
        return max(0, (float) ($line['RemainingOpenQuantity'] ?? $line['OpenQuantity'] ?? $line['Quantity'] ?? 0));
    }
}
