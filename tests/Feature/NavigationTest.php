<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_open_every_sidebar_page(): void
    {
        $user = $this->operationalUser(UserRole::SuperAdmin);

        $this->withoutVite();
        Http::fake(fn (ClientRequest $request) => str_ends_with($request->url(), '/Login')
            ? Http::response(['SessionId' => 'navigation-session'])
            : Http::response(['value' => []]));

        foreach ([
            'dashboard' => 'Dashboard Operasional',
            'purchase-requests.index' => 'Daftar Purchase Request',
            'purchase-progress.index' => 'Dokumen Pembelian',
            'sparepart-report.index' => 'Report Beban Sparepart',
            'ready-to-receive.index' => 'Daftar Goods Receipt PO',
            'goods-issues.index' => 'Daftar Goods Issue',
            'payment-requests.index' => 'Business Flow yang Dikendalikan',
            'payment-approvals.index' => 'List Approval Payment',
            'admin.integration-monitor' => 'SAP API Log Terbaru',
            'admin.users.index' => 'User Terdaftar',
            'admin.configuration' => 'Database SAP dan Master Data Cache',
        ] as $route => $visibleText) {
            $this->actingAs($user)->get(route($route))->assertOk()->assertSee($visibleText);
        }
    }

    public function test_dashboard_contains_clickable_menu_links_for_admin(): void
    {
        $user = $this->operationalUser(UserRole::SuperAdmin);

        $response = $this->withoutVite()->actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        foreach ([
            'purchase-requests.index',
            'purchase-progress.index',
            'sparepart-report.index',
            'ready-to-receive.index',
            'goods-issues.index',
            'admin.integration-monitor',
            'admin.users.index',
            'admin.configuration',
        ] as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }

        $response
            ->assertDontSee('href="'.route('payment-requests.index').'"', false)
            ->assertDontSee('href="'.route('payment-approvals.index').'"', false)
            ->assertDontSee('>KEUANGAN<', false);
    }

    public function test_purchase_request_uses_remote_searchable_item_combobox(): void
    {
        $user = $this->operationalUser(UserRole::SuperAdmin);

        $response = $this->withoutVite()
            ->actingAs($user)
            ->get(route('purchase-requests.create'));

        $response->assertOk()
            ->assertSee('data-item-combobox', false)
            ->assertSee('data-item-search', false)
            ->assertSee('data-url="'.route('lookups.items', ['context' => 'purchase-request']).'"', false)
            ->assertDontSee('data-item-select', false);
    }

    public function test_purchase_request_and_goods_issue_use_remote_sap_vehicle_search(): void
    {
        $user = $this->operationalUser(UserRole::SuperAdmin);

        foreach (['purchase-requests.create', 'goods-issues.create'] as $route) {
            $response = $this->withoutVite()->actingAs($user)->get(route($route));

            $response->assertOk()
                ->assertSee('data-vehicle-combobox', false)
                ->assertSee('data-vehicle-search', false)
                ->assertSee('data-url="'.route('lookups.vehicles').'"', false)
                ->assertSee('name="vehicle_cost_center_id"', false)
                ->assertDontSee('name="vehicle_id"', false);
        }
    }

    public function test_list_pages_do_not_render_transaction_entry_forms(): void
    {
        $user = $this->operationalUser(UserRole::SuperAdmin);

        $this->withoutVite()->actingAs($user)->get(route('purchase-requests.index'))
            ->assertOk()
            ->assertDontSee('data-sap-form', false)
            ->assertSee('href="'.route('purchase-requests.create').'"', false);
        $this->withoutVite()->actingAs($user)->get(route('goods-issues.index'))
            ->assertOk()
            ->assertDontSee('data-sap-form', false)
            ->assertSee('href="'.route('goods-issues.create').'"', false);
        $this->withoutVite()->actingAs($user)->get(route('payment-requests.index'))
            ->assertOk()
            ->assertDontSee('data-payment-request-form', false)
            ->assertSee('href="'.route('payment-requests.create').'"', false);
        $this->withoutVite()->actingAs($user)->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('data-user-registration', false)
            ->assertSee('href="'.route('admin.users.create').'"', false);
    }

    #[DataProvider('restrictedPages')]
    public function test_manager_operational_cannot_open_transaction_entry_or_admin_pages(string $route): void
    {
        $user = $this->operationalUser(UserRole::ManagerOperational);

        $this->actingAs($user)->get(route($route))->assertForbidden();
    }

    public function test_supervisor_can_open_purchase_progress(): void
    {
        $user = $this->operationalUser(UserRole::ManagerOperational);

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('purchase-progress.index'))
            ->assertOk()
            ->assertSee('Purchase Progress');
    }

    public function test_manager_operational_can_open_read_only_operational_lists_without_create_actions(): void
    {
        $user = $this->operationalUser(UserRole::ManagerOperational);

        foreach (['purchase-requests.index', 'ready-to-receive.index', 'goods-issues.index'] as $route) {
            $this->withoutVite()
                ->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertDontSee('Buat Purchase Request')
                ->assertDontSee('Buat Goods Issue');
        }
    }

    /** @return array<string, array{string}> */
    public static function restrictedPages(): array
    {
        return [
            'create purchase request' => ['purchase-requests.create'],
            'create goods issue' => ['goods-issues.create'],
            'payment request' => ['payment-requests.index'],
            'payment approval' => ['payment-approvals.index'],
            'integration monitor' => ['admin.integration-monitor'],
            'register user' => ['admin.users.index'],
            'create user' => ['admin.users.create'],
            'system configuration' => ['admin.configuration'],
        ];
    }

    private function operationalUser(UserRole $role): User
    {
        $pool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-tsp',
            'code' => 'TSP',
            'name' => 'Transport',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);

        return $user;
    }
}
