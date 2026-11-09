<?php

namespace App\Application\Users;

use App\Integration\Sap\MasterData\WarehouseGateway;
use App\Integration\Sap\SapCompanyContext;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Active SAP warehouses of one CompanyDB, read with GET only. Used to verify that a warehouse assigned to a user exists
 * in the company the mapping is for, which can differ from the company Admin HO is logged into.
 */
class CompanyWarehouseDirectory
{
    public function __construct(
        private readonly SapCompanyContext $companies,
        private readonly WarehouseGateway $warehouses,
    ) {}

    /**
     * @return list<string> upper-case active warehouse codes
     *
     * @throws Throwable when SAP cannot be read; callers must fail closed
     */
    public function activeCodes(string $companyDb): array
    {
        return Cache::remember(
            'sap:warehouses:active:'.$companyDb,
            now()->addMinutes(5),
            function () use ($companyDb): array {
                $previousCompanyDb = $this->companies->current();
                $this->companies->select($companyDb);

                try {
                    return array_values(array_unique(array_map(
                        fn (string $code): string => strtoupper(trim($code)),
                        $this->warehouses->activeWarehouseCodes(),
                    )));
                } finally {
                    $this->companies->select($previousCompanyDb);
                }
            },
        );
    }

    public function isActive(string $companyDb, string $warehouseCode): bool
    {
        return in_array(strtoupper(trim($warehouseCode)), $this->activeCodes($companyDb), true);
    }
}
