<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ListFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_purchase_request_list_can_search_document_data_and_preserves_filter(): void
    {
        [$user, $pool, $department] = $this->operationalUser();
        SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_doc_num' => '260001001',
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'vehicle_cost_center' => ['center_code' => 'BK8815FG']],
        ]);
        SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_doc_num' => '260001002',
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'vehicle_cost_center' => ['center_code' => 'BK9921XY']],
        ]);

        $response = $this->withoutVite()->actingAs($user)->get(route('purchase-requests.index', ['q' => 'BK8815FG']));

        $response->assertOk()
            ->assertSee('260001001')
            ->assertDontSee('260001002')
            ->assertSee('value="BK8815FG"', false);
    }

    public function test_goods_issue_and_user_lists_render_search_and_status_filters(): void
    {
        [$user] = $this->operationalUser(UserRole::SuperAdmin);

        $this->withoutVite()->actingAs($user)->get(route('goods-issues.index'))
            ->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('name="status"', false);
        $this->withoutVite()->actingAs($user)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Cari nama, email, Warehouse, Pool, atau Department')
            ->assertSee('Status User');
    }

    /** @return array{User, ControlPool, ControlDepartment} */
    private function operationalUser(UserRole $role = UserRole::AdminOperational): array
    {
        $pool = ControlPool::query()->create(['code' => 'DUMAI'.fake()->unique()->numerify('###'), 'name' => 'Dumai', 'is_active' => true]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => fake()->uuid(),
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

        return [$user, $pool, $department];
    }
}
