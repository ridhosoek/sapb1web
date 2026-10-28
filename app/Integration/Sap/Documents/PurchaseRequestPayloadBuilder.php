<?php

namespace App\Integration\Sap\Documents;

use App\Integration\Sap\Mapping\SapFieldMapper;
use App\Integration\Sap\SapMetadataInspector;
use App\Models\SapCostCenterCache;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PurchaseRequestPayloadBuilder
{
    public function __construct(
        private readonly SapFieldMapper $fields,
        private readonly SapMetadataInspector $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, SapItemCache>  $items
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(
        array $data,
        User $user,
        SapCostCenterCache $departmentCostCenter,
        SapCostCenterCache $poolCostCenter,
        SapCostCenterCache $vehicleCostCenter,
        Collection $items,
        array $context = [],
    ): array {
        $profile = $user->operationalProfile;
        $warehouseCode = (string) ($context['warehouse_code'] ?? $profile->warehouse_code);
        $payload = [
            'DocDate' => $data['document_date'] ?? now()->toDateString(),
            'DocDueDate' => $data['required_date'],
            // SAP Service Layer exposes this header property with its official misspelling.
            'RequriedDate' => $data['required_date'],
            'DocumentLines' => collect($data['lines'])->map(function (array $line) use ($data, $departmentCostCenter, $poolCostCenter, $vehicleCostCenter, $warehouseCode, $items): array {
                $item = $items->get((int) $line['item_id']);
                $documentLine = [
                    'ItemCode' => $item->item_code,
                    'Quantity' => (float) $line['quantity'],
                    'WarehouseCode' => $warehouseCode,
                    'RequiredDate' => $data['required_date'],
                    config('sap.purchase_request.department_costing_field') => $departmentCostCenter->center_code,
                    config('sap.purchase_request.pool_costing_field') => $poolCostCenter->center_code,
                    config('sap.purchase_request.vehicle_costing_field') => $vehicleCostCenter->center_code,
                ];

                if (filled($line['free_text'] ?? null)) {
                    $documentLine['FreeText'] = Str::limit($line['free_text'], 100, '');
                }

                return $documentLine;
            })->values()->all(),
        ];

        if (filled($data['notes'] ?? null)) {
            $payload['Comments'] = Str::limit($data['notes'], 254, '');
        }

        $priorityField = $this->fields->optionalField('priority');

        if ($priorityField !== null && $this->metadata->supportsEntityProperty('PurchaseRequests', $priorityField)) {
            $payload[$priorityField] = $data['priority'];
        }

        $currentKmField = $this->fields->optionalField('current_km');

        if ($currentKmField !== null && $this->metadata->supportsEntityProperty('PurchaseRequests', $currentKmField)) {
            $payload[$currentKmField] = (int) $data['ending_km'];
        }

        $transactionField = $this->fields->optionalField('web_transaction_id');

        if ($transactionField !== null && $this->metadata->supportsEntityProperty('PurchaseRequests', $transactionField)) {
            $payload[$transactionField] = $data['transaction_uuid'];
        }

        $webCreatedByField = $this->fields->optionalField('web_created_by_user');

        if ($webCreatedByField !== null && $this->metadata->supportsEntityProperty('PurchaseRequests', $webCreatedByField)) {
            $payload[$webCreatedByField] = Str::limit(
                (string) ($context['created_by_web_user'] ?? $user->name),
                100,
                '',
            );
        }

        if ($this->metadata->supportsEntityProperty('PurchaseRequests', 'RequesterEmail')) {
            $payload['RequesterEmail'] = (string) ($context['requester_email'] ?? $user->email);
        }

        return $payload;
    }
}
