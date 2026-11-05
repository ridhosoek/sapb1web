<?php

namespace App\Integration\Sap\Documents;

use App\Integration\Sap\Mapping\SapFieldMapper;
use App\Integration\Sap\SapMetadataInspector;
use App\Models\SapCostCenterCache;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GoodsIssuePayloadBuilder
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
        ?string $legacyVehicleCode,
        SapCostCenterCache $departmentCostCenter,
        SapCostCenterCache $poolCostCenter,
        Collection $items,
        array $context = [],
    ): array {
        $profile = $user->operationalProfile;
        $warehouseCode = (string) ($context['warehouse_code'] ?? $profile->warehouse_code);
        $poolCode = (string) ($context['pool_code'] ?? $profile->pool->code);
        $departmentCode = (string) ($context['department_code'] ?? $profile->department->code);
        $poolName = trim((string) ($context['pool_name'] ?? $profile->pool->name ?? $poolCode));
        $vehicleCodes = collect($data['lines'])
            ->map(fn (array $line): string => trim((string) data_get($line, 'vehicle_cost_center.center_code')))
            ->filter()
            ->unique()
            ->implode(', ');
        $reference = implode(' - ', [
            trim((string) $data['fpk_number']),
            $vehicleCodes !== '' ? $vehicleCodes : '-',
            $poolName !== '' ? $poolName : $poolCode,
        ]);
        $legacyAccountCode = trim((string) ($context['account_code'] ?? ''));
        $departmentField = (string) config('sap.goods_issue.department_costing_field', 'CostingCode');
        $poolField = (string) config('sap.goods_issue.pool_costing_field', 'CostingCode2');
        $vehicleField = (string) config('sap.goods_issue.vehicle_costing_field', 'CostingCode3');
        $issueTypeField = (string) config('sap.goods_issue.issue_type_field', 'U_VTPI');
        $currentKmField = (string) config('sap.goods_issue.current_km_field', 'U_KM1');

        $payload = [
            'DocDate' => $data['fpk_date'],
            'TaxDate' => $data['fpk_date'],
            'Reference2' => $data['fpk_number'],
            'Comments' => Str::limit($reference, 254, ''),
            'JournalMemo' => Str::limit($reference, 50, ''),
            'DocumentLines' => collect($data['lines'])->map(function (array $line) use ($items, $warehouseCode, $legacyAccountCode, $departmentField, $poolField, $vehicleField, $issueTypeField, $currentKmField, $legacyVehicleCode, $departmentCostCenter, $poolCostCenter): array {
                $item = $items->get((int) $line['item_id']);
                $accountCode = trim((string) data_get($line, 'issue_type.account_code', $legacyAccountCode));
                $vehicleCode = trim((string) data_get($line, 'vehicle_cost_center.center_code', $legacyVehicleCode));
                $documentLine = [
                    'ItemCode' => $item->item_code,
                    'Quantity' => (float) $line['quantity'],
                    'WarehouseCode' => $warehouseCode,
                    $departmentField => $departmentCostCenter->center_code,
                    $poolField => $poolCostCenter->center_code,
                    $issueTypeField => (string) data_get($line, 'issue_type.code'),
                    $currentKmField => (int) $line['ending_km'],
                ];

                if ($accountCode !== '') {
                    $documentLine['AccountCode'] = $accountCode;
                }

                if ($vehicleCode !== '') {
                    $documentLine[$vehicleField] = $vehicleCode;
                }

                if ($line['serial_numbers'] !== []) {
                    $documentLine['SerialNumbers'] = collect($line['serial_numbers'])
                        ->map(fn (string $serialNumber): array => [
                            'InternalSerialNumber' => $serialNumber,
                            'Quantity' => 1,
                        ])->values()->all();
                }

                return $documentLine;
            })->values()->all(),
        ];

        foreach ([
            'web_transaction_id' => $data['transaction_uuid'],
            'web_created_by_user' => Str::limit($user->name, 100, ''),
            'fpk_number' => $data['fpk_number'],
            'pool' => $poolCode,
            'department' => $departmentCode,
        ] as $businessName => $value) {
            $field = $this->fields->optionalField($businessName);

            if ($field !== null && $this->metadata->supportsEntityProperty('InventoryGenExits', $field)) {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }
}
