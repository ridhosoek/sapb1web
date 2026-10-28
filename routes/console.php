<?php

use App\Jobs\RefreshControlCenterCache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sap:post-approved', ['--limit' => 25])
    ->everyTwoMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::job(new RefreshControlCenterCache)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

$sapCompanyDatabases = array_values(array_unique(array_filter([
    ...config('sap.company_databases', []),
    config('sap.company_db'),
])));

foreach ($sapCompanyDatabases as $companyDb) {
    Schedule::command('sap:purchase-progress:sync', [
        '--company-db' => $companyDb,
        '--limit' => (int) config('sap.watchers.batch_size', 50),
    ])
        ->name("sap-purchase-progress-sync-{$companyDb}")
        ->everyTwoMinutes()
        ->onOneServer()
        ->withoutOverlapping(10);

    Schedule::command('sap:items:sync', [
        '--company-db' => $companyDb,
        '--page-size' => 100,
    ])
        ->name("sap-items-sync-{$companyDb}")
        ->dailyAt('01:00')
        ->onOneServer()
        ->withoutOverlapping(120);

    foreach ([1, 2, (int) config('sap.dimensions.vehicle')] as $offset => $dimension) {
        Schedule::command('sap:cost-centers:sync', [
            '--company-db' => $companyDb,
            '--dimension' => $dimension,
            '--page-size' => 100,
        ])
            ->name("sap-cost-centers-sync-{$companyDb}-dimension-{$dimension}")
            ->dailyAt('01:'.(30 + ($offset * 5)))
            ->onOneServer()
            ->withoutOverlapping(120);
    }
}
