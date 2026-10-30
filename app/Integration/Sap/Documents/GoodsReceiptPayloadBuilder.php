<?php

namespace App\Integration\Sap\Documents;

use App\Integration\Sap\Mapping\SapFieldMapper;
use App\Integration\Sap\SapMetadataInspector;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Support\Str;

class GoodsReceiptPayloadBuilder
{
    public function __construct(
        private readonly SapFieldMapper $fields,
        private readonly SapMetadataInspector $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $purchaseOrderData
     * @param  list<array{line_num: int, quantity: float, warehouse_code: string}>  $receiptLines
     * @return array<string, mixed>
     */
    public function build(
        SapDocument $purchaseOrder,
        array $purchaseOrderData,
        string $receiptDate,
        array $receiptLines,
        User $user,
    ): array {
        $comments = "GRPO Web dari PO {$purchaseOrder->sap_doc_num} oleh {$user->name}";
        $payload = [
            'DocDate' => $receiptDate,
            'DocDueDate' => $receiptDate,
            'TaxDate' => $receiptDate,
            'Comments' => Str::limit($comments, 254, ''),
            'DocumentLines' => collect($receiptLines)->map(fn (array $line): array => [
                'BaseType' => (int) config('sap.object_types.purchase_order', 22),
                'BaseEntry' => (int) $purchaseOrder->sap_doc_entry,
                'BaseLine' => $line['line_num'],
                'Quantity' => $line['quantity'],
                'WarehouseCode' => $line['warehouse_code'],
            ])->values()->all(),
        ];

        $cardCode = trim((string) ($purchaseOrderData['CardCode'] ?? ''));

        if ($cardCode !== '') {
            $payload['CardCode'] = $cardCode;
        }

        $webCreatedByField = $this->fields->optionalField('web_created_by_user');

        if ($webCreatedByField !== null && $this->metadata->supportsEntityProperty('PurchaseDeliveryNotes', $webCreatedByField)) {
            $payload[$webCreatedByField] = Str::limit($user->name, 100, '');
        }

        return $payload;
    }
}
