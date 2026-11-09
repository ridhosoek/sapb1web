<?php

namespace Tests\Feature;

use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockMonitoringTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB']);
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
    }

    public function test_legacy_stock_monitoring_url_redirects_to_sparepart_report(): void
    {
        $user = $this->operationalUser('DMI-01');

        $response = $this->withoutVite()->actingAs($user)->get(route('stock-monitoring.index'));

        $response->assertRedirect(route('sparepart-report.index'));
    }

    public function test_item_opens_a_company_and_warehouse_scoped_stock_ledger(): void
    {
        $user = $this->operationalUser('DMI-01');
        $stock = $this->stock('TEST_DB', 'DMI-01', 'ITEM-LEDGER', 10, 2, 3, 4);

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/Login')) {
                return Http::response(['SessionId' => 'session-ledger']);
            }

            if (str_contains($request->url(), 'InventoryGenExits')) {
                return Http::response(['value' => [[
                    'InventoryGenExits' => [
                        'DocEntry' => 88,
                        'DocNum' => 260000088,
                        'DocDate' => '2026-10-20',
                        'Cancelled' => 'N',
                        'Reference2' => 'FPK-0088',
                        'Comments' => 'Pengeluaran test',
                    ],
                    'InventoryGenExits/DocumentLines' => [
                        'LineNum' => 0,
                        'ItemCode' => 'ITEM-LEDGER',
                        'Quantity' => 2,
                        'WarehouseCode' => 'DMI-01',
                    ],
                ]]]);
            }

            return Http::response(['value' => []]);
        });

        $response = $this->withoutVite()->actingAs($user)->get(route('stock-monitoring.show', $stock));

        $response->assertOk()
            ->assertSee('ITEM-LEDGER')
            ->assertSee('Stock Ledger')
            ->assertSee('260000088')
            ->assertSee('FPK-0088')
            ->assertSee('2,00');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'InventoryGenExits'));
    }

    public function test_stock_ledger_rejects_stock_from_another_warehouse(): void
    {
        $user = $this->operationalUser('DMI-01');
        $otherWarehouseStock = $this->stock('TEST_DB', 'MDN-01', 'OTHER-WH', 10, 0, 0, 2);
        Http::preventStrayRequests();

        $response = $this->withoutVite()->actingAs($user)->get(route('stock-monitoring.show', $otherWarehouseStock));

        $response->assertNotFound();
        Http::assertNothingSent();
    }

    private function operationalUser(string $warehouseCode): User
    {
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
            'warehouse_code' => $warehouseCode,
        ]);

        return $user;
    }

    private function stock(
        string $companyDb,
        string $warehouseCode,
        string $itemCode,
        float $inStock,
        float $committed,
        float $ordered,
        float $minimum,
    ): SapItemWarehouseStock {
        $item = SapItemCache::query()->create([
            'company_db' => $companyDb,
            'item_code' => $itemCode,
            'item_name' => 'Item '.$itemCode,
            'inventory_uom' => 'PCS',
            'is_inventory_item' => true,
            'is_active' => true,
        ]);

        return SapItemWarehouseStock::query()->create([
            'company_db' => $companyDb,
            'sap_item_cache_id' => $item->id,
            'warehouse_code' => $warehouseCode,
            'in_stock' => $inStock,
            'committed_stock' => $committed,
            'available_stock' => $inStock - $committed,
            'ordered_stock' => $ordered,
            'minimum_stock' => $minimum,
            'is_locked' => false,
            'synced_at' => now(),
        ]);
    }
}
