<?php

namespace Tests\Feature;

use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCostCenterCache;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationalContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_without_operational_profile_is_blocked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_vehicle_lookup_searches_active_sap_vehicle_cost_centers_in_selected_company(): void
    {
        [$user] = $this->operationalUser('DUMAI');

        SapCostCenterCache::query()->create(['company_db' => 'TEST_DB', 'center_code' => 'BK8748ES', 'center_name' => 'Mobil Tangki', 'dimension' => 3, 'is_active' => true]);
        SapCostCenterCache::query()->create(['company_db' => 'TEST_DB', 'center_code' => 'BK8748XX', 'center_name' => 'Nonaktif', 'dimension' => 3, 'is_active' => false]);
        SapCostCenterCache::query()->create(['company_db' => 'SECOND_DB', 'center_code' => 'BK8748ZZ', 'center_name' => 'Database Lain', 'dimension' => 3, 'is_active' => true]);
        SapCostCenterCache::query()->create(['company_db' => 'TEST_DB', 'center_code' => 'DMI', 'center_name' => 'Pool Dumai', 'dimension' => 2, 'is_active' => true]);

        $response = $this->actingAs($user)->getJson(route('lookups.vehicles', ['q' => '8748']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.center_code', 'BK8748ES');
        $response->assertJsonPath('meta.has_more', false);
    }

    public function test_vehicle_lookup_requires_two_search_characters(): void
    {
        [$user] = $this->operationalUser('DUMAI');

        $response = $this->actingAs($user)->getJson(route('lookups.vehicles', ['q' => 'B']));

        $response->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_vehicle_lookup_recovers_an_empty_company_cache_from_sap(): void
    {
        [$user] = $this->operationalUser('DUMAI');
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://sap.test/b1s/v2/Login') {
                return Http::response(['SessionId' => 'vehicle-session']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['value' => (int) ($query['$skip'] ?? 0) === 0 ? [[
                'CenterCode' => 'BK8748ES',
                'CenterName' => 'Mobil Tangki',
                'InWhichDimension' => 3,
                'Active' => 'tYES',
            ]] : []]);
        });

        $response = $this->actingAs($user)->getJson(route('lookups.vehicles', ['q' => '8748']));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.center_code', 'BK8748ES')
            ->assertJsonPath('meta.cache_recovered', true);
    }

    public function test_item_lookup_returns_only_active_inventory_items(): void
    {
        [$user] = $this->operationalUser('DUMAI');
        $availableItem = SapItemCache::query()->create(['company_db' => 'TEST_DB', 'item_code' => 'I00790', 'item_name' => 'Kain Majun', 'inventory_uom' => 'KG', 'is_active' => true, 'is_inventory_item' => true]);
        $zeroStockItem = SapItemCache::query()->create(['company_db' => 'TEST_DB', 'item_code' => 'I00791', 'item_name' => 'Kain Lap', 'inventory_uom' => 'PCS', 'is_active' => true, 'is_inventory_item' => true]);
        $otherWarehouseItem = SapItemCache::query()->create(['company_db' => 'TEST_DB', 'item_code' => 'I00792', 'item_name' => 'Kain Gudang Lain', 'inventory_uom' => 'PCS', 'is_active' => true, 'is_inventory_item' => true]);
        SapItemCache::query()->create(['company_db' => 'TEST_DB', 'item_code' => 'I00001', 'item_name' => 'Kain Nonaktif', 'is_active' => false, 'is_inventory_item' => true]);
        SapItemCache::query()->create(['company_db' => 'TEST_DB', 'item_code' => 'S00001', 'item_name' => 'Jasa Kain', 'is_active' => true, 'is_inventory_item' => false]);
        SapItemCache::query()->create(['company_db' => 'SECOND_DB', 'item_code' => 'I99999', 'item_name' => 'Kain Database Lain', 'is_active' => true, 'is_inventory_item' => true]);
        $this->warehouseStock($availableItem, 'TEST_DB', 8, 2);
        $this->warehouseStock($zeroStockItem, 'TEST_DB', 0, 0);
        $this->warehouseStock($otherWarehouseItem, 'TEST_DB', 5, 0, 'MDN-01');

        $response = $this->actingAs($user)->getJson(route('lookups.items', ['q' => 'kain']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.item_code', 'I00790');
        $response->assertJsonPath('data.0.available_stock', 6);
        $response->assertJsonMissing(['item_code' => 'I00791']);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.has_more', false);
    }

    public function test_goods_issue_item_lookup_recovers_an_empty_warehouse_cache_from_sap(): void
    {
        [$user] = $this->operationalUser('DUMAI');
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://sap.test/b1s/v2/Login') {
                return Http::response(['SessionId' => 'session-123']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ((int) ($query['$skip'] ?? 0) > 0) {
                return Http::response(['value' => []]);
            }

            return Http::response(['value' => [[
                'Items' => ['ItemCode' => 'I06595', 'ItemName' => 'Dinamo Starter', 'InventoryUOM' => 'PCS'],
                'Items/ItemWarehouseInfoCollection' => [
                    'WarehouseCode' => 'DMI-01',
                    'InStock' => 1,
                    'Committed' => 0,
                    'Ordered' => 0,
                    'MinimalStock' => 0,
                    'Locked' => 'N',
                ],
            ]]]);
        });

        $response = $this->actingAs($user)->getJson(route('lookups.items', [
            'context' => 'goods-issue',
            'q' => 'I06595',
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_code', 'I06595')
            ->assertJsonPath('data.0.item_name', 'Dinamo Starter')
            ->assertJsonPath('data.0.available_stock', 1)
            ->assertJsonPath('meta.warehouse_cache_recovered', true);
    }

    public function test_item_lookup_can_page_through_all_active_items_without_a_search_term(): void
    {
        [$user] = $this->operationalUser('DUMAI');

        foreach (['I00001', 'I00002', 'I00003'] as $itemCode) {
            $item = SapItemCache::query()->create([
                'company_db' => 'TEST_DB',
                'item_code' => $itemCode,
                'item_name' => 'Item '.$itemCode,
                'inventory_uom' => 'PCS',
                'is_active' => true,
                'is_inventory_item' => true,
            ]);
            $this->warehouseStock($item, 'TEST_DB', 10, 1);
        }

        $firstPage = $this->actingAs($user)->getJson(route('lookups.items', [
            'page' => 1,
            'per_page' => 2,
        ]));

        $firstPage->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.item_code', 'I00001')
            ->assertJsonPath('data.1.item_code', 'I00002')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.has_more', true);

        $secondPage = $this->actingAs($user)->getJson(route('lookups.items', [
            'page' => 2,
            'per_page' => 2,
        ]));

        $secondPage->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_code', 'I00003')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_purchase_request_item_lookup_includes_active_purchase_items_that_are_not_inventory_items(): void
    {
        [$user] = $this->operationalUser('DUMAI');
        $mainItem = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'SERVICE-01',
            'item_name' => 'Jasa Perbaikan',
            'is_active' => true,
            'is_inventory_item' => false,
            'is_purchase_item' => true,
        ]);
        $secondItem = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'INVENTORY-ONLY',
            'item_name' => 'Bukan Purchase Item',
            'is_active' => true,
            'is_inventory_item' => true,
            'is_purchase_item' => false,
        ]);

        $response = $this->actingAs($user)->getJson(route('lookups.items', [
            'context' => 'purchase-request',
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_code', 'SERVICE-01');
    }

    public function test_purchase_request_item_lookup_recovers_an_empty_company_cache_from_sap(): void
    {
        [$user] = $this->operationalUser('DUMAI');
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://sap.test/b1s/v2/Login') {
                return Http::response(['SessionId' => 'purchase-item-session']);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $skip = (int) ($query['$skip'] ?? 0);

            if (str_ends_with($path, '/ItemGroups')) {
                return Http::response(['value' => $skip === 0
                    ? [['Number' => 100, 'GroupName' => 'SPAREPART']]
                    : []]);
            }

            return Http::response(['value' => $skip === 0 ? [[
                'ItemCode' => 'I02042',
                'ItemName' => 'Baterai Yuasa',
                'InventoryUOM' => 'PCS',
                'ItemsGroupCode' => 100,
                'InventoryItem' => 'tYES',
                'PurchaseItem' => 'tYES',
                'ManageSerialNumbers' => 'tNO',
                'SRIAndBatchManageMethod' => 'bomm_OnEveryTransaction',
                'Frozen' => 'tNO',
            ]] : []]);
        });

        $response = $this->actingAs($user)->getJson(route('lookups.items', [
            'context' => 'purchase-request',
            'q' => 'I02042',
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_code', 'I02042')
            ->assertJsonPath('meta.master_cache_recovered', true);
    }

    public function test_direct_browser_navigation_to_item_lookup_returns_to_purchase_request_form(): void
    {
        [$user] = $this->operationalUser('DUMAI');

        $response = $this->actingAs($user)
            ->withHeader('Sec-Fetch-Mode', 'navigate')
            ->get(route('lookups.items', [
                'context' => 'purchase-request',
                'q' => 'JASA LAS',
            ]));

        $response
            ->assertRedirect(route('purchase-requests.create'))
            ->assertSessionHas(
                'warning',
                'Pencarian item digunakan langsung dari form transaksi. Silakan pilih item dari kolom pencarian di halaman ini.',
            );
    }

    public function test_item_lookup_uses_the_company_database_selected_during_login(): void
    {
        [$user] = $this->operationalUser('DUMAI');
        $mainItem = SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'SHARED',
            'item_name' => 'Item Database Utama',
            'is_active' => true,
            'is_inventory_item' => true,
        ]);
        $secondItem = SapItemCache::query()->create([
            'company_db' => 'SECOND_DB',
            'item_code' => 'SHARED',
            'item_name' => 'Item Database Kedua',
            'is_active' => true,
            'is_inventory_item' => true,
        ]);
        $this->warehouseStock($mainItem, 'TEST_DB', 5, 0);
        $this->warehouseStock($secondItem, 'SECOND_DB', 7, 1);

        $response = $this->actingAs($user)
            ->withSession(['sap_company_db' => 'SECOND_DB'])
            ->getJson(route('lookups.items', ['q' => 'SHARED']));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_name', 'Item Database Kedua');
    }

    /** @return array{User, ControlPool} */
    private function operationalUser(string $poolCode): array
    {
        $pool = ControlPool::query()->create(['code' => $poolCode, 'name' => $poolCode, 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-'.$poolCode,
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

        return [$user, $pool];
    }

    private function warehouseStock(SapItemCache $item, string $companyDb, float $inStock, float $committed, string $warehouseCode = 'DMI-01'): void
    {
        SapItemWarehouseStock::query()->create([
            'company_db' => $companyDb,
            'sap_item_cache_id' => $item->id,
            'warehouse_code' => $warehouseCode,
            'in_stock' => $inStock,
            'committed_stock' => $committed,
            'available_stock' => $inStock - $committed,
            'is_locked' => false,
            'synced_at' => now(),
        ]);
    }
}
