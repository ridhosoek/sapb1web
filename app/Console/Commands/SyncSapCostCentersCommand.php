<?php

namespace App\Console\Commands;

use App\Application\CostCenters\SyncSapCostCenters;
use App\Integration\Sap\SapCompanyContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sap:cost-centers:sync
    {--dimension= : SAP dimension number; defaults to the configured Nopol dimension}
    {--limit=0 : Maximum number of cost centers to synchronize; zero reads all}
    {--page-size=20 : Number of cost centers requested from SAP per page (1-100)}
    {--company-db= : Allowed SAP company database; defaults to SAP_COMPANY_DB}')]
#[Description('Read active SAP B1 cost centers for one dimension into the local cache')]
class SyncSapCostCentersCommand extends Command
{
    public function handle(SyncSapCostCenters $sync, SapCompanyContext $companies): int
    {
        $dimension = filter_var($this->option('dimension') ?: config('sap.dimensions.vehicle'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $pageSize = filter_var($this->option('page-size'), FILTER_VALIDATE_INT);

        if ($dimension === false || $dimension < 1 || $dimension > 5) {
            $this->error('The --dimension option must be between 1 and 5.');

            return self::INVALID;
        }

        if ($limit === false || $limit < 0) {
            $this->error('The --limit option must be zero or a positive integer.');

            return self::INVALID;
        }

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
        $this->info("Company database: {$companyDb}");
        $this->info("Reading active SAP cost centers for dimension {$dimension}...");
        $result = $sync->handle($dimension, $limit, $pageSize);
        $this->info("Synchronized {$result['processed']} cost centers: {$result['created']} created, {$result['updated']} updated.");

        if ($limit === 0) {
            $this->info("Deactivated {$result['deactivated']} stale cost centers.");
        }

        return self::SUCCESS;
    }
}
