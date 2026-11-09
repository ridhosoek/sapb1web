<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCostCenterCache;
use App\Models\SapItemCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_effective_configuration_without_credentials(): void
    {
        $admin = $this->operationalUser(UserRole::SuperAdmin);
        config()->set('sap.base_url', 'https://sap.example.test:50000/b1s/v2');
        config()->set('sap.password', 'top-secret-password');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB']);
        SapItemCache::query()->create([
            'company_db' => 'TEST_DB',
            'item_code' => 'I00001',
            'item_name' => 'Item Test',
            'is_active' => true,
            'is_purchase_item' => true,
        ]);
        SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'TSP',
            'center_name' => 'Transport',
            'dimension' => 1,
            'is_active' => true,
        ]);

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.configuration'));

        $response->assertOk()
            ->assertSee('Konfigurasi Sistem')
            ->assertSee('https://sap.example.test:50000/b1s/v2')
            ->assertSee('TEST_DB')
            ->assertSee('U_WebTxnId')
            ->assertDontSee('top-secret-password');
    }

    public function test_non_admin_cannot_view_system_configuration(): void
    {
        $supervisor = $this->operationalUser(UserRole::ManagerOperational);

        $this->actingAs($supervisor)->get(route('admin.configuration'))->assertForbidden();
    }

    private function operationalUser(UserRole $role): User
    {
        $pool = ControlPool::query()->create(['code' => fake()->unique()->bothify('POOL-###'), 'name' => 'Pool Test', 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => fake()->unique()->uuid(),
            'code' => fake()->unique()->bothify('DEPT-###'),
            'name' => 'Department Test',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);

        return $user;
    }
}
