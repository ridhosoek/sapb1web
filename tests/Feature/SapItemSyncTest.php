<?php

namespace Tests\Feature;

use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapItemCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SapItemSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_reads_paginated_non_deactivated_items_from_sap_and_updates_local_cache(): void
    {
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://sap.test/b1s/v2/Login') {
                return Http::response(['SessionId' => 'session-123'], 200, ['Set-Cookie' => 'ROUTEID=.node1; Path=/']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['value' => match ((int) ($query['$skip'] ?? 0)) {
                0 => [
                    ['ItemCode' => 'A001', 'ItemName' => 'Oli Mesin', 'InventoryUOM' => 'LTR', 'InventoryItem' => 'tYES', 'PurchaseItem' => 'tYES', 'Frozen' => 'tNO'],
                    ['ItemCode' => 'A002', 'ItemName' => 'Jasa Bengkel', 'InventoryUOM' => null, 'InventoryItem' => 'tNO', 'PurchaseItem' => 'tYES', 'Frozen' => 'tNO'],
                ],
                2 => [
                    ['ItemCode' => 'A003', 'ItemName' => 'Kain Majun', 'InventoryUOM' => 'KG', 'InventoryItem' => 'tYES', 'PurchaseItem' => 'tNO', 'Frozen' => 'tNO'],
                ],
                default => [],
            }]);
        });

        $this->artisan('sap:items:sync', ['--page-size' => 2])
            ->expectsOutput('Reading active items from SAP B1 Service Layer...')
            ->expectsOutput('Synchronized 3 items: 3 created, 0 updated.')
            ->assertSuccessful();

        $this->assertDatabaseHas('sap_items_cache', [
            'company_db' => 'TEST_DB',
            'item_code' => 'A001',
            'item_name' => 'Oli Mesin',
            'inventory_uom' => 'LTR',
            'is_inventory_item' => true,
            'is_purchase_item' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('sap_items_cache', [
            'company_db' => 'TEST_DB',
            'item_code' => 'A002',
            'is_inventory_item' => false,
            'is_purchase_item' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('sap_items_cache', [
            'company_db' => 'TEST_DB',
            'item_code' => 'A003',
            'item_name' => 'Kain Majun',
            'inventory_uom' => 'KG',
        ]);
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), 'https://sap.test/b1s/v2/Items?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['$select'] ?? null) === 'ItemCode,ItemName,InventoryUOM,InventoryItem,PurchaseItem,Frozen'
                && ($query['$filter'] ?? null) === "Frozen eq 'tNO'"
                && ($query['$orderby'] ?? null) === 'ItemCode asc';
        });
    }

    public function test_command_rejects_an_invalid_page_size_before_contacting_sap(): void
    {
        Http::preventStrayRequests();

        $this->artisan('sap:items:sync', ['--page-size' => 101])
            ->expectsOutput('The --page-size option must be between 1 and 100.')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_full_sync_reads_warehouse_stock_for_mapped_user_warehouses(): void
    {
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');
        $pool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-tsp',
            'code' => 'TSP',
            'name' => 'Transport',
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://sap.test/b1s/v2/Login') {
                return Http::response(['SessionId' => 'session-123']);
            }

            if (str_contains($request->url(), '%24crossjoin') || str_contains($request->url(), '$crossjoin')) {
                return Http::response(['value' => [[
                    'Items' => ['ItemCode' => 'I02042', 'ItemName' => 'Baterai Yuasa', 'InventoryUOM' => 'PCS'],
                    'Items/ItemWarehouseInfoCollection' => [
                        'WarehouseCode' => 'DMI-01',
                        'InStock' => 8,
                        'Committed' => 2,
                        'Ordered' => 3,
                        'MinimalStock' => 5,
                        'Locked' => 'N',
                    ],
                ]]]);
            }

            return Http::response(['value' => [[
                'ItemCode' => 'I02042',
                'ItemName' => 'Baterai Yuasa',
                'InventoryUOM' => 'PCS',
                'InventoryItem' => 'tYES',
                'PurchaseItem' => 'tYES',
                'Frozen' => 'tNO',
            ]]]);
        });

        $this->artisan('sap:items:sync', ['--page-size' => 10])
            ->expectsOutput('Synchronized 1 warehouse stock rows across 1 configured warehouses; removed 0 stale rows.')
            ->assertSuccessful();

        $item = SapItemCache::query()->where('item_code', 'I02042')->firstOrFail();
        $this->assertDatabaseHas('sap_item_warehouse_stocks', [
            'company_db' => 'TEST_DB',
            'sap_item_cache_id' => $item->id,
            'warehouse_code' => 'DMI-01',
            'in_stock' => 8,
            'committed_stock' => 2,
            'available_stock' => 6,
            'ordered_stock' => 3,
            'minimum_stock' => 5,
            'is_locked' => false,
        ]);
    }

    public function test_command_rejects_an_unconfigured_company_database_before_contacting_sap(): void
    {
        Http::preventStrayRequests();

        $this->artisan('sap:items:sync', ['--company-db' => 'UNTRUSTED_DB'])
            ->expectsOutput('The --company-db option must be one of: TEST_DB, SECOND_DB')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_full_sync_keeps_nameless_sap_items_and_deactivates_stale_cached_items(): void
    {
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');

        SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'STALE-01',
            'item_name' => 'Item Lama',
            'is_inventory_item' => true,
            'is_active' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://sap.test/b1s/v2/Login') {
                return Http::response(['SessionId' => 'session-123']);
            }

            return Http::response(['value' => [
                ['ItemCode' => 'ACTIVE-01', 'ItemName' => '', 'InventoryUOM' => 'PCS', 'InventoryItem' => 'tYES', 'PurchaseItem' => 'tYES', 'Frozen' => 'tNO'],
            ]]);
        });

        $this->artisan('sap:items:sync', ['--page-size' => 10])
            ->expectsOutput('Synchronized 1 items: 1 created, 0 updated.')
            ->expectsOutput('Deactivated 1 stale cached items.')
            ->assertSuccessful();

        $this->assertDatabaseHas('sap_items_cache', [
            'company_db' => 'TEST_DB',
            'item_code' => 'ACTIVE-01',
            'item_name' => '(Tanpa nama)',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('sap_items_cache', [
            'item_code' => 'STALE-01',
            'is_active' => false,
        ]);
    }
}
