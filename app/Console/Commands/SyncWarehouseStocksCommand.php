<?php

namespace App\Console\Commands;

use App\Application\Items\SyncSapItems;
use App\Integration\Sap\MasterData\WarehouseGateway;
use App\Integration\Sap\SapCompanyContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sap:warehouse-stocks:sync
    {--company-db= : Allowed SAP company database; defaults to SAP_COMPANY_DB}
    {--warehouse=* : Warehouse code to synchronize; repeat the option for multiple warehouses}
    {--page-size=100 : Number of warehouse rows requested from SAP per page (1-100)}')]
#[Description('Synchronize active inventory items and stock for operational warehouses')]
class SyncWarehouseStocksCommand extends Command
{
    public function handle(
        SyncSapItems $sync,
        SapCompanyContext $companies,
        WarehouseGateway $warehouseGateway,
    ): int {
        $pageSize = filter_var($this->option('page-size'), FILTER_VALIDATE_INT);

        if ($pageSize === false || $pageSize < 1 || $pageSize > 100) {
            $this->error('The --page-size option must be between 1 and 100.');

            return self::INVALID;
        }

        $companyDb = $this->option('company-db') ?: $companies->current();

        if (! $companies->isAllowed($companyDb)) {
            $this->error('The --company-db option must be one of: '.implode(', ', $companies->available()));

            return self::INVALID;
        }

        $companies->select($companyDb);
        $requestedWarehouses = array_values(array_filter(
            $this->option('warehouse'),
            fn (mixed $warehouseCode): bool => is_string($warehouseCode) && trim($warehouseCode) !== '',
        ));
        $warehouseCodes = $requestedWarehouses === []
            ? $warehouseGateway->activeWarehouseCodes($pageSize)
            : $requestedWarehouses;
        $result = $sync->synchronizeWarehouses($warehouseCodes, $pageSize);

        $this->info("Company database: {$companyDb}");
        $this->info("Synchronized {$result['processed']} warehouse stock rows across {$result['warehouses']} warehouses; removed {$result['removed']} stale rows.");

        return self::SUCCESS;
    }
}
