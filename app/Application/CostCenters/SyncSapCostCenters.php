<?php

namespace App\Application\CostCenters;

use App\Integration\Sap\MasterData\ProfitCenterGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCostCenterCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class SyncSapCostCenters
{
    public function __construct(
        private readonly ProfitCenterGateway $costCenters,
        private readonly SapCompanyContext $companies,
    ) {}

    /** @return array{processed: int, created: int, updated: int, deactivated: int} */
    public function handle(int $dimension, int $limit = 0, int $pageSize = 20): array
    {
        $processed = 0;
        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $skip = 0;
        $synchronizedCodes = [];
        $companyDb = $this->companies->current();

        do {
            $remaining = $limit > 0 ? $limit - $processed : $pageSize;
            $requested = min($pageSize, $remaining);

            if ($requested <= 0) {
                break;
            }

            $page = $this->costCenters->activeByDimension($dimension, $requested, $skip);

            DB::transaction(function () use ($page, $dimension, $companyDb, &$created, &$updated, &$synchronizedCodes): void {
                foreach ($page as $costCenter) {
                    $code = $costCenter['CenterCode'] ?? null;

                    if (! is_string($code) || trim($code) === '') {
                        throw new UnexpectedValueException('SAP ProfitCenter is missing CenterCode.');
                    }

                    $name = is_string($costCenter['CenterName'] ?? null) && trim($costCenter['CenterName']) !== ''
                        ? trim($costCenter['CenterName'])
                        : trim($code);

                    $cachedCostCenter = SapCostCenterCache::query()->updateOrCreate(
                        ['company_db' => $companyDb, 'center_code' => trim($code)],
                        [
                            'center_name' => $name,
                            'dimension' => $dimension,
                            'is_active' => ($costCenter['Active'] ?? null) === 'tYES',
                            'source_payload' => $costCenter,
                            'synced_at' => now(),
                        ],
                    );

                    $cachedCostCenter->wasRecentlyCreated ? $created++ : $updated++;
                    $synchronizedCodes[trim($code)] = true;
                }
            });

            $received = count($page);
            $processed += $received;
            $skip += $received;
        } while ($received === $requested && ($limit === 0 || $processed < $limit));

        if ($limit === 0) {
            SapCostCenterCache::query()
                ->forCompany($companyDb)
                ->where('dimension', $dimension)
                ->where('is_active', true)
                ->select(['id', 'center_code'])
                ->chunkById(500, function (Collection $cachedCostCenters) use (&$deactivated, $synchronizedCodes): void {
                    $staleIds = $cachedCostCenters
                        ->reject(fn (SapCostCenterCache $costCenter): bool => isset($synchronizedCodes[$costCenter->center_code]))
                        ->pluck('id');

                    if ($staleIds->isNotEmpty()) {
                        $deactivated += SapCostCenterCache::query()
                            ->whereKey($staleIds)
                            ->update(['is_active' => false]);
                    }
                });
        }

        return compact('processed', 'created', 'updated', 'deactivated');
    }
}
