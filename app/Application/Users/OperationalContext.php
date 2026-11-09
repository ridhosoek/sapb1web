<?php

namespace App\Application\Users;

use App\Models\SapCostCenterCache;
use App\Models\UserOperationalProfile;

/**
 * The validated operational mapping of one user in one SAP CompanyDB. {@see snapshot()} is what a document keeps at
 * submission so that later posting never depends on the profile as it is edited afterwards.
 */
final readonly class OperationalContext
{
    public function __construct(
        public string $companyDb,
        public UserOperationalProfile $profile,
        public SapCostCenterCache $poolCostCenter,
        public SapCostCenterCache $departmentCostCenter,
    ) {}

    public function warehouseCode(): string
    {
        return trim((string) $this->profile->warehouse_code);
    }

    /** @return array{company_db: string, profile_id: int, pool_id: int, pool_code: string, pool_name: string, department_id: int, department_code: string, department_name: string, warehouse_code: string} */
    public function snapshot(): array
    {
        return [
            'company_db' => $this->companyDb,
            'profile_id' => $this->profile->id,
            'pool_id' => $this->profile->pool_id,
            // Codes and names are those of the SAP company's own cost centers, not of the shared Control Center rows.
            'pool_code' => $this->poolCostCenter->center_code,
            'pool_name' => $this->poolCostCenter->center_name,
            'department_id' => $this->profile->department_id,
            'department_code' => $this->departmentCostCenter->center_code,
            'department_name' => $this->departmentCostCenter->center_name,
            'warehouse_code' => $this->warehouseCode(),
        ];
    }
}
