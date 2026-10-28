<?php

namespace Tests\Feature;

use App\Models\SapCostCenterCache;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SapCostCenterSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_syncs_only_active_cost_centers_from_the_nopol_dimension(): void
    {
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');
        config()->set('sap.dimensions.vehicle', 3);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://sap.test/b1s/v2/Login') {
                return Http::response(['SessionId' => 'session-123']);
            }

            return Http::response(['value' => [
                ['CenterCode' => 'L8568UZ', 'CenterName' => 'L8568UZ', 'InWhichDimension' => 3, 'Active' => 'tYES'],
                ['CenterCode' => 'BK8748ES', 'CenterName' => 'BK8748ES', 'InWhichDimension' => 3, 'Active' => 'tYES'],
            ]]);
        });

        $this->artisan('sap:cost-centers:sync', ['--page-size' => 10])
            ->expectsOutput('Reading active SAP cost centers for dimension 3...')
            ->expectsOutput('Synchronized 2 cost centers: 2 created, 0 updated.')
            ->assertSuccessful();

        $this->assertDatabaseHas('sap_cost_center_caches', [
            'company_db' => 'TEST_DB',
            'center_code' => 'L8568UZ',
            'dimension' => 3,
            'is_active' => true,
        ]);
        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), 'https://sap.test/b1s/v2/ProfitCenters?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['$select'] ?? null) === 'CenterCode,CenterName,InWhichDimension,Active'
                && ($query['$filter'] ?? null) === "InWhichDimension eq 3 and Active eq 'tYES'"
                && ($query['$orderby'] ?? null) === 'CenterCode asc';
        });
    }

    public function test_full_sync_deactivates_stale_nopol_cost_centers_only_in_the_selected_company_and_dimension(): void
    {
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');
        config()->set('sap.dimensions.vehicle', 3);

        SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'STALE-NOPOL',
            'center_name' => 'STALE-NOPOL',
            'dimension' => 3,
            'is_active' => true,
        ]);
        SapCostCenterCache::query()->create([
            'company_db' => 'SECOND_DB',
            'center_code' => 'OTHER-COMPANY',
            'center_name' => 'OTHER-COMPANY',
            'dimension' => 3,
            'is_active' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-123']),
            'https://sap.test/b1s/v2/ProfitCenters*' => Http::response(['value' => []]),
        ]);

        $this->artisan('sap:cost-centers:sync', ['--page-size' => 10])
            ->expectsOutput('Deactivated 1 stale cost centers.')
            ->assertSuccessful();

        $this->assertDatabaseHas('sap_cost_center_caches', [
            'company_db' => 'TEST_DB',
            'center_code' => 'STALE-NOPOL',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('sap_cost_center_caches', [
            'company_db' => 'SECOND_DB',
            'center_code' => 'OTHER-COMPANY',
            'is_active' => true,
        ]);
    }
}
