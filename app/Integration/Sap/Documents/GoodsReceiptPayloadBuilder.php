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
     * @param  list<array<string, mixed>>  $receiptLines
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
            'DocumentLines' => collect($receiptLines)->map(function (array $line) use ($purchaseOrder): array {
                $documentLine = [
                    'BaseType' => (int) config('sap.object_types.purchase_order', 22),
                    'BaseEntry' => (int) $purchaseOrder->sap_doc_entry,
                    'BaseLine' => $line['line_num'],
                    'Quantity' => $line['quantity'],
                    'WarehouseCode' => $line['warehouse_code'],
                ];

                if (($line['serial_numbers'] ?? []) !== []) {
                    $documentLine['SerialNumbers'] = collect($line['serial_numbers'])
                        ->filter(fn (mixed $serialNumber): bool => is_array($serialNumber))
                        ->map(fn (array $serialNumber): array => $this->serialNumberPayload($serialNumber))
                        ->values()
                        ->all();
                }

                return $documentLine;
            })->values()->all(),
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

    /**
     * @param  array<string, mixed>  $serialNumber
     * @return array<string, mixed>
     */
    private function serialNumberPayload(array $serialNumber): array
    {
        $fieldMap = [
            'manufacturer_serial_number' => 'ManufacturerSerialNumber',
            'internal_serial_number' => 'InternalSerialNumber',
            'lot_number' => 'BatchID',
            'expiry_date' => 'ExpiryDate',
            'manufacture_date' => 'ManufactureDate',
            'reception_date' => 'ReceptionDate',
            'location' => 'Location',
            'notes' => 'Notes',
        ];
        $payload = ['Quantity' => 1];

        foreach ($fieldMap as $sourceField => $sapField) {
            if (filled($serialNumber[$sourceField] ?? null)) {
                $payload[$sapField] = $serialNumber[$sourceField];
            }
        }

        return $payload;
    }
}
