<?php

namespace App\Console\Commands;

use App\Application\Items\SyncSapItems;
use App\Integration\Sap\SapCompanyContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sap:items:sync
    {--limit=0 : Maximum number of items to synchronize; zero reads all items}
    {--page-size=20 : Number of items requested from SAP per page (1-100)}
    {--company-db= : Allowed SAP company database; defaults to SAP_COMPANY_DB}')]
#[Description('Read active items from SAP B1 Service Layer into the local search cache')]
class SyncSapItemsCommand extends Command
{
    public function handle(SyncSapItems $sync, SapCompanyContext $companies): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $pageSize = filter_var($this->option('page-size'), FILTER_VALIDATE_INT);

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
        $this->info('Reading active items from SAP B1 Service Layer...');
        $result = $sync->handle($limit, $pageSize);
        $this->info("Synchronized {$result['processed']} items: {$result['created']} created, {$result['updated']} updated.");

        if ($limit === 0) {
            $this->info("Deactivated {$result['deactivated']} stale cached items.");
            $this->info("Synchronized {$result['stock_processed']} warehouse stock rows across {$result['warehouses']} configured warehouses; removed {$result['stock_removed']} stale rows.");
        }

        return self::SUCCESS;
    }
}
