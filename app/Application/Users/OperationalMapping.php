<?php

namespace App\Application\Users;

use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Mapping\SapCostCenterResolver;
use App\Models\User;
use App\Models\UserOperationalProfile;

/**
 * Resolves the Pool, Department and Warehouse that Admin HO assigned to a user for one SAP CompanyDB (TD-09). It never
 * falls back to another company's mapping and never guesses a counterpart by name.
 */
class OperationalMapping
{
    public function __construct(private readonly SapCostCenterResolver $costCenters) {}

    /**
     * @throws SapSubmissionException when the mapping of the company is missing, incomplete or inactive
     */
    public function resolve(User $user, string $companyDb): OperationalContext
    {
        $profile = $user->operationalProfileFor($companyDb);
        $profile?->load('pool', 'department');
        $this->assertComplete($profile, $companyDb);

        return new OperationalContext(
            companyDb: $companyDb,
            profile: $profile,
            poolCostCenter: $this->costCenters->resolve($companyDb, (int) config('sap.dimensions.pool'), $profile->pool->code, 'Pool'),
            departmentCostCenter: $this->costCenters->resolve($companyDb, (int) config('sap.dimensions.department'), $profile->department->code, 'Department'),
        );
    }

    /**
     * Reason in Indonesian why the current mapping cannot be used, or null when the basic mapping is usable. This
     * checks the local mapping only; {@see resolve()} also verifies the SAP cost centers.
     */
    public function incompleteReason(?UserOperationalProfile $profile, string $companyDb): ?string
    {
        if ($profile === null) {
            return "Pemetaan Pool, Department, dan Warehouse untuk database SAP {$companyDb} belum ditetapkan oleh Admin HO.";
        }

        if (trim((string) $profile->warehouse_code) === '') {
            return "Warehouse untuk database SAP {$companyDb} belum ditetapkan oleh Admin HO.";
        }

        if (! $profile->pool?->is_active) {
            return "Pool untuk database SAP {$companyDb} tidak aktif atau belum ditetapkan oleh Admin HO.";
        }

        if (! $profile->department?->is_active) {
            return "Department untuk database SAP {$companyDb} tidak aktif atau belum ditetapkan oleh Admin HO.";
        }

        return null;
    }

    private function assertComplete(?UserOperationalProfile $profile, string $companyDb): void
    {
        $reason = $this->incompleteReason($profile, $companyDb);

        if ($reason !== null) {
            throw new SapSubmissionException("{$reason} Transaksi tidak dapat dibuat sampai pemetaan operasional user dilengkapi.");
        }
    }
}
