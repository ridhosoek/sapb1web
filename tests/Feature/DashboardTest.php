<?php

namespace Tests\Feature;

use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_operational_user_can_view_action_oriented_dashboard(): void
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
            'warehouse_code' => 'DMI-01',
        ]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('Dashboard Operasional')
            ->assertSee('Action Required')
            ->assertSee('DMI-01 · TSP')
            ->assertSee('Cari ItemCode atau ItemName')
            ->assertDontSee('@livewireStyles')
            ->assertDontSee('@livewireScripts');
    }
}
