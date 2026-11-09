<?php

namespace App\Console\Commands;

use App\Application\Users\CompanyWarehouseDirectory;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCostCenterCache;
use App\Models\UserOperationalProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('sap:operational-profiles:backfill
    {--company-db= : SAP company database the legacy profiles are proven for (required)}
    {--apply : Write the changes; without it the command only reports (dry run)}')]
#[Description('Attach legacy operational profiles (company_db NULL) to one company only when SAP proves them (GET only; TD-09)')]
class BackfillOperationalProfilesCommand extends Command
{
    public function handle(SapCompanyContext $companies, CompanyWarehouseDirectory $warehouses): int
    {
        $companyDb = (string) $this->option('company-db');

        if (! $companies->isAllowed($companyDb)) {
            $this->error('The --company-db option is required and must be one of: '.implode(', ', $companies->available()));

            return self::INVALID;
        }

        $companies->select($companyDb);

        try {
            // Always read the live list: a cached list could hide a warehouse that was deactivated meanwhile.
            Cache::forget('sap:warehouses:active:'.$companyDb);
            $activeWarehouses = $warehouses->activeCodes($companyDb);
        } catch (Throwable $exception) {
            report($exception);
            $this->error("SAP warehouses of {$companyDb} could not be read; nothing was changed.");

            return self::FAILURE;
        }

        $activeCostCenterCodes = fn (int $dimension): array => SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', $dimension)
            ->where('is_active', true)
            ->pluck('center_code')
            ->map(fn (string $code): string => mb_strtolower(trim($code)))
            ->flip()
            ->all();
        $poolCodes = $activeCostCenterCodes((int) config('sap.dimensions.pool'));
        $departmentCodes = $activeCostCenterCodes((int) config('sap.dimensions.department'));
        $apply = (bool) $this->option('apply');
        $mapped = 0;
        $skipped = 0;

        UserOperationalProfile::query()
            ->whereNull('company_db')
            ->with('pool', 'department')
            ->orderBy('id')
            ->each(function (UserOperationalProfile $profile) use ($companyDb, $activeWarehouses, $poolCodes, $departmentCodes, $apply, &$mapped, &$skipped): void {
                $reason = match (true) {
                    ! $profile->pool?->is_active || ! $profile->department?->is_active => 'Pool atau Department nonaktif',
                    ! isset($poolCodes[mb_strtolower(trim($profile->pool->code))]) => "kode Pool {$profile->pool->code} tidak ada sebagai cost center aktif",
                    ! isset($departmentCodes[mb_strtolower(trim($profile->department->code))]) => "kode Department {$profile->department->code} tidak ada sebagai cost center aktif",
                    ! in_array(strtoupper(trim((string) $profile->warehouse_code)), $activeWarehouses, true) => "warehouse {$profile->warehouse_code} tidak aktif di SAP",
                    UserOperationalProfile::query()->where('user_id', $profile->user_id)->forCompany($companyDb)->exists() => 'user sudah memiliki pemetaan untuk company ini',
                    default => null,
                };

                if ($reason !== null) {
                    $skipped++;
                    $this->line("Profil {$profile->id} (user {$profile->user_id}) dilewati: {$reason}.");

                    return;
                }

                $mapped++;
                $this->info("Profil {$profile->id} (user {$profile->user_id}) terbukti untuk {$companyDb}".($apply ? ' dan dipetakan.' : ' (dry run).'));

                if ($apply) {
                    $profile->update(['company_db' => $companyDb]);
                    AuditLog::query()->create([
                        'actor_id' => null,
                        'event' => 'user.operational_profile_backfilled',
                        'auditable_type' => UserOperationalProfile::class,
                        'auditable_id' => $profile->id,
                        'metadata' => [
                            'company_db' => $companyDb,
                            'pool' => $profile->pool->code,
                            'department' => $profile->department->code,
                            'warehouse_code' => $profile->warehouse_code,
                        ],
                    ]);
                }
            });

        $this->line(($apply ? 'Dipetakan' : 'Dapat dipetakan').": {$mapped}; dilewati (menunggu Admin HO): {$skipped}.");

        return self::SUCCESS;
    }
}
