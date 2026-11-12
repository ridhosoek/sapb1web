<?php

namespace App\Application\Receiving;

use App\Application\Purchasing\PurchaseRequestReceiptProgress;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Integration\Sap\Documents\PurchaseOrderGateway;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapDocumentRelation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores a Goods Receipt PO that SAP is known to hold (a successful POST or a reconciliation by UUID), links it
 * to its Purchase Order, resolves the command, and then refreshes the Purchase Order and request progress from
 * SAP. Only used with partial receipts enabled (TD-05).
 */
class RecordGoodsReceipt
{
    public function __construct(
        private readonly PurchaseOrderGateway $purchaseOrders,
        private readonly PurchaseOrderReceiptProgress $purchaseOrderProgress,
        private readonly PurchaseRequestReceiptProgress $requestProgress,
    ) {}

    /**
     * @param  array<string, mixed>  $sapDocument  Goods Receipt PO as returned by SAP
     * @param  list<array<string, mixed>>  $receiptLines
     * @param  array<string, mixed>  $auditMetadata
     */
    public function handle(
        SapDocument $purchaseOrder,
        SapCommand $command,
        array $sapDocument,
        array $receiptLines,
        string $receiptDate,
        string $discoveredBy,
        string $auditEvent,
        ?int $actorId,
        ?string $ipAddress,
        array $auditMetadata = [],
    ): SapDocument {
        $docEntry = (int) $sapDocument['DocEntry'];
        $isCancelled = in_array(strtoupper((string) ($sapDocument['Cancelled'] ?? 'N')), ['Y', 'C', 'TYES'], true);
        $goodsReceipt = DB::transaction(function () use ($purchaseOrder, $command, $sapDocument, $receiptLines, $receiptDate, $discoveredBy, $auditEvent, $actorId, $ipAddress, $auditMetadata, $docEntry, $isCancelled): SapDocument {
            $goodsReceipt = SapDocument::query()->updateOrCreate(
                [
                    'company_db' => $purchaseOrder->company_db,
                    'sap_entity' => 'PurchaseDeliveryNotes',
                    'sap_doc_entry' => $docEntry,
                ],
                [
                    'pool_id' => $purchaseOrder->pool_id,
                    'department_id' => $purchaseOrder->department_id,
                    'document_type' => 'GOODS_RECEIPT_PO',
                    'sap_doc_num' => filled($sapDocument['DocNum'] ?? null) ? (string) $sapDocument['DocNum'] : null,
                    'source' => 'WEB',
                    'fpk_number' => $purchaseOrder->fpk_number,
                    'transaction_uuid' => $command->transaction_uuid,
                    'sap_business_status' => $isCancelled ? BusinessStatus::Cancelled : BusinessStatus::Open,
                    'integration_status' => IntegrationStatus::InSync,
                    'created_from_web' => true,
                    'normalized_data' => [
                        'base_document_type' => 'PURCHASE_ORDER',
                        'base_document_entry' => $purchaseOrder->sap_doc_entry,
                        'base_document_num' => $purchaseOrder->sap_doc_num,
                        'receipt_date' => $receiptDate,
                        'vendor_code' => $sapDocument['CardCode'] ?? null,
                        'vendor_name' => $sapDocument['CardName'] ?? null,
                        'warehouse_code' => data_get($receiptLines, '0.warehouse_code'),
                        'request_uuid' => $command->transaction_uuid,
                        'document' => $sapDocument,
                        'lines' => $receiptLines,
                    ],
                ],
            );

            // The owner is the command's first sender, never the actor of this call (who may be a reconciler).
            if ($goodsReceipt->web_created_by === null && $command->requested_by !== null) {
                $goodsReceipt->update(['web_created_by' => $command->requested_by]);
            }

            SapDocumentRelation::query()->updateOrCreate(
                [
                    'parent_document_id' => $purchaseOrder->id,
                    'child_document_id' => $goodsReceipt->id,
                    'relation_type' => 'GOODS_RECEIPT_PO',
                ],
                [
                    'discovered_by' => $discoveredBy,
                    'relation_metadata' => [
                        'base_type' => (int) config('sap.object_types.purchase_order', 22),
                        'base_entry' => $purchaseOrder->sap_doc_entry,
                        'line_count' => count($receiptLines),
                        'request_uuid' => $command->transaction_uuid,
                        'lines' => collect($receiptLines)
                            ->map(fn (array $line): array => ['base_line' => $line['line_num'], 'quantity' => $line['quantity']])
                            ->values()
                            ->all(),
                    ],
                ],
            );

            $command->update([
                'status' => IntegrationStatus::InSync,
                'sap_doc_entry' => $docEntry,
                'sap_doc_num' => $goodsReceipt->sap_doc_num,
                'last_error' => null,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actorId,
                'event' => $auditEvent,
                'auditable_type' => SapDocument::class,
                'auditable_id' => $goodsReceipt->id,
                'metadata' => [
                    'company_db' => $purchaseOrder->company_db,
                    'purchase_order_id' => $purchaseOrder->id,
                    'purchase_order_doc_num' => $purchaseOrder->sap_doc_num,
                    'warehouse_code' => data_get($receiptLines, '0.warehouse_code'),
                    'sap_doc_entry' => $docEntry,
                    'sap_doc_num' => $goodsReceipt->sap_doc_num,
                    'request_uuid' => $command->transaction_uuid,
                    'requested_by' => $command->requested_by,
                    ...$auditMetadata,
                ],
                'ip_address' => $ipAddress,
            ]);

            return $goodsReceipt;
        });

        $this->refreshProgress($purchaseOrder);

        return $goodsReceipt->fresh();
    }

    /**
     * The receipt is already safe in SAP and in the portal, so a failed refresh must not turn it into an error:
     * the open quantity is simply marked stale until the next read (Ready sync, watcher, or the next posting).
     */
    private function refreshProgress(SapDocument $purchaseOrder): void
    {
        try {
            $this->purchaseOrderProgress->apply($purchaseOrder, $this->purchaseOrders->find((int) $purchaseOrder->sap_doc_entry));
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Purchase Order could not be re-read from SAP after a Goods Receipt PO; open quantity marked stale.', [
                'company_db' => $purchaseOrder->company_db,
                'purchase_order_doc_entry' => $purchaseOrder->sap_doc_entry,
            ]);
            $normalizedData = $purchaseOrder->normalized_data ?? [];
            $normalizedData['progress_status'] = 'PARTIALLY_RECEIVED';
            $normalizedData['fully_received'] = false;
            $normalizedData['receipt_state_stale'] = true;
            $purchaseOrder->update(['normalized_data' => $normalizedData]);
        }

        $this->requestProgress->refreshForPurchaseOrder($purchaseOrder->refresh());
    }
}
