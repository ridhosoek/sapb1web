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
        ?SapCostCenterCache $legacyVehicleCostCenter,
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
            'DocumentLines' => collect($data['lines'])->map(function (array $line) use ($data, $departmentCostCenter, $poolCostCenter, $legacyVehicleCostCenter, $warehouseCode, $items): array {
                $item = $items->get((int) $line['item_id']);
                $vehicleCode = trim((string) data_get(
                    $line,
                    'vehicle_cost_center.center_code',
                    $legacyVehicleCostCenter?->center_code,
                ));
                $documentLine = [
                    'ItemCode' => $item->item_code,
                    'Quantity' => (float) $line['quantity'],
                    'WarehouseCode' => $warehouseCode,
                    'RequiredDate' => $data['required_date'],
                    config('sap.purchase_request.department_costing_field') => $departmentCostCenter->center_code,
                    config('sap.purchase_request.pool_costing_field') => $poolCostCenter->center_code,
                ];

                if ($vehicleCode !== '') {
                    $documentLine[config('sap.purchase_request.vehicle_costing_field')] = $vehicleCode;
                }

                if (filled($line['free_text'] ?? null)) {
                    $documentLine['FreeText'] = Str::limit($line['free_text'], 100, '');
                }

                if (filled($line['manual_uom'] ?? null)) {
                    $documentLine['MeasureUnit'] = Str::limit(trim((string) $line['manual_uom']), 100, '');
                }

                $documentLine[(string) config('sap.purchase_request.current_km_field', 'U_KM1')] = (int) $line['ending_km'];

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

        $transactionField = $this->fields->optionalField('web_transaction_id');

        if ($transactionField !== null && $this->metadata->supportsEntityProperty('PurchaseRequests', $transactionField)) {
            $payload[$transactionField] = $data['transaction_uuid'];
        } elseif ($transactionField !== null) {
            $this->metadata->reportUnsupportedEntityProperty('PurchaseRequests', $transactionField, 'web_transaction_id');
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
