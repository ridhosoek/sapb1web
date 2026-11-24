<?php

namespace Tests\Feature;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\DocumentApproval;
use App\Models\DocumentApprovalStep;
use App\Models\SapDocument;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
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
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'lines' => [['vehicle_cost_center' => ['center_code' => 'BK8815FG']]]],
        ]);
        SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_doc_num' => '260001002',
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'lines' => [['vehicle_cost_center' => ['center_code' => 'BK9921XY']]]],
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
            ->assertSee('Cari nama, jabatan, email, Warehouse, Pool, atau Department')
            ->assertSee('Status User');
    }

    public function test_goods_issue_list_finds_the_displayed_portal_number_and_filters_displayed_statuses(): void
    {
        [$user, $pool, $department] = $this->operationalUser();
        $pending = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'GOODS_ISSUE',
            'sap_doc_entry' => null,
            'sap_doc_num' => null,
            'fpk_number' => '025473',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::PendingPost,
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'fpk_date' => '2026-11-24', 'lines' => []],
        ]);
        SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'GOODS_ISSUE',
            'sap_doc_num' => '260006886',
            'fpk_number' => '025634',
            'sap_business_status' => BusinessStatus::Closed,
            'integration_status' => IntegrationStatus::InSync,
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'lines' => []],
        ]);

        $this->withoutVite()->actingAs($user)->get(route('goods-issues.index', ['q' => '#'.$pending->id]))
            ->assertOk()
            ->assertSee('025473')
            ->assertDontSee('025634');

        $this->withoutVite()->actingAs($user)->get(route('goods-issues.index', ['q' => (string) $pending->id]))
            ->assertOk()
            ->assertSee('025473');

        $this->withoutVite()->actingAs($user)->get(route('goods-issues.index', [
            'business_status' => 'CLOSED', 'integration_status' => 'IN_SYNC',
        ]))->assertOk()
            ->assertSee('025634')
            ->assertDontSee('025473')
            ->assertSee('name="business_status"', false)
            ->assertSee('name="integration_status"', false);
    }

    public function test_purchase_request_list_finds_pending_portal_number_and_filters_integration(): void
    {
        [$user, $pool, $department] = $this->operationalUser();
        $pending = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_doc_entry' => null,
            'sap_doc_num' => null,
            'integration_status' => IntegrationStatus::PendingPost,
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'lines' => []],
        ]);
        SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_doc_num' => '260001777',
            'integration_status' => IntegrationStatus::InSync,
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'lines' => []],
        ]);

        $this->withoutVite()->actingAs($user)->get(route('purchase-requests.index', ['q' => '#'.$pending->id]))
            ->assertOk()
            ->assertSee('#'.$pending->id)
            ->assertDontSee('260001777');

        $this->withoutVite()->actingAs($user)->get(route('purchase-progress.index', ['q' => '#'.$pending->id]))
            ->assertOk()
            ->assertSee('#'.$pending->id)
            ->assertDontSee('260001777');

        $this->withoutVite()->actingAs($user)->get(route('purchase-requests.index', ['integration_status' => 'IN_SYNC']))
            ->assertOk()
            ->assertSee('260001777')
            ->assertDontSee('#'.$pending->id);
    }

    public function test_goods_issue_approval_queue_finds_pending_portal_number(): void
    {
        [$approver, $pool, $department] = $this->operationalUser(UserRole::AdminHo);
        $document = SapDocument::factory()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'GOODS_ISSUE',
            'sap_doc_entry' => null,
            'sap_doc_num' => null,
            'fpk_number' => '025473',
            'normalized_data' => ['warehouse_code' => 'DMI-01', 'lines' => []],
        ]);
        $approval = DocumentApproval::query()->create([
            'sap_document_id' => $document->id,
            'submitted_by' => $approver->id,
            'status' => 'PENDING',
            'current_step' => 1,
            'submitted_at' => now(),
        ]);
        DocumentApprovalStep::query()->create([
            'document_approval_id' => $approval->id,
            'approver_id' => $approver->id,
            'approver_name' => $approver->name,
            'step_order' => 1,
            'status' => 'PENDING',
            'token' => (string) Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withoutVite()->actingAs($approver)->get(route('goods-issue-approvals.index', ['q' => '#'.$document->id]))
            ->assertOk()
            ->assertSee('Goods Issue #'.$document->id)
            ->assertSee('025473');

        $this->withoutVite()->actingAs($approver)->get(route('goods-issue-approvals.index', ['q' => $pool->code]))
            ->assertOk()
            ->assertSee('Goods Issue #'.$document->id);
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
