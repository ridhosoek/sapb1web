<?php

namespace App\Application\Receiving;

use App\Integration\Sap\Documents\GoodsReceiptGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\User;
use RuntimeException;

/**
 * Finds the Goods Receipt PO that belongs to an uncertain command by the request UUID stored in the SAP UDF.
 *
 * Found: the receipt is recorded and the command resolved. Not found: nothing changes, because an empty answer
 * never proves that the POST did not happen. Any failure or ambiguity leaves the command as it was.
 */
class ReconcileGoodsReceipt
{
    public function __construct(
        private readonly GoodsReceiptGateway $goodsReceipts,
        private readonly RecordGoodsReceipt $recordGoodsReceipt,
        private readonly SapCompanyContext $companies,
    ) {}

    /** Whether the stored request carried the UUID in the SAP UDF, i.e. whether a lookup by UUID can find it. */
    public function canLookUp(SapCommand $command): bool
    {
        $field = trim((string) config('sap.fields.web_transaction_id'));

        return $field !== '' && data_get($command->request_payload, $field) === $command->transaction_uuid;
    }

    public function handle(SapCommand $command, ?User $actor = null, ?string $ipAddress = null): ?SapDocument
    {
        $purchaseOrder = SapDocument::query()
            ->forCompany($command->company_db)
            ->where('document_type', 'PURCHASE_ORDER')
            ->where('sap_doc_entry', $command->base_document_entry)
            ->first();

        if ($purchaseOrder === null) {
            throw new RuntimeException('Purchase Order untuk command GRPO tidak ditemukan di portal.');
        }

        if (! $this->canLookUp($command)) {
            return null;
        }

        $this->companies->select($command->company_db);
        $headers = $this->goodsReceipts->findByTransactionUuid($command->transaction_uuid);

        if ($headers === []) {
            return null;
        }

        if (count($headers) > 1) {
            throw new RuntimeException('Lebih dari satu Goods Receipt PO membawa UUID yang sama; perlu investigasi manual.');
        }

        $document = $this->goodsReceipts->find((int) $headers[0]['DocEntry']);
        $lines = collect($document['DocumentLines'] ?? [])->filter(fn (mixed $line): bool => is_array($line))->values();
        $isBasedOnPurchaseOrder = $lines->isNotEmpty() && $lines->every(
            fn (array $line): bool => (int) ($line['BaseType'] ?? 0) === (int) config('sap.object_types.purchase_order', 22)
                && (int) ($line['BaseEntry'] ?? 0) === (int) $purchaseOrder->sap_doc_entry,
        );

        if (! $isBasedOnPurchaseOrder) {
            throw new RuntimeException('Goods Receipt PO dengan UUID ini tidak berdasar pada Purchase Order command; perlu investigasi manual.');
        }

        $receiptLines = $lines->map(fn (array $line): array => [
            'line_num' => (int) ($line['BaseLine'] ?? 0),
            'quantity' => (float) ($line['Quantity'] ?? 0),
            'item_code' => (string) ($line['ItemCode'] ?? ''),
            'item_name' => (string) ($line['ItemDescription'] ?? ''),
            'warehouse_code' => (string) ($line['WarehouseCode'] ?? ''),
            'serial_numbers' => [],
        ])->all();

        return $this->recordGoodsReceipt->handle(
            purchaseOrder: $purchaseOrder,
            command: $command,
            sapDocument: $document,
            receiptLines: $receiptLines,
            receiptDate: substr((string) ($document['DocDate'] ?? now()->toDateString()), 0, 10),
            discoveredBy: 'RECONCILIATION',
            auditEvent: 'goods_receipt_po.reconciled',
            actorId: $actor?->id,
            ipAddress: $ipAddress,
            auditMetadata: ['reconciled_from_status' => $command->status->value],
        );
    }
}
