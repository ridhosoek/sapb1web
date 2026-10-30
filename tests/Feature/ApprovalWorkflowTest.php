<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ApprovalWorkflow;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_purchase_request_workflow_starts_with_two_levels_and_lists_manager_admin_ho(): void
    {
        $admin = $this->operationalUser(UserRole::SuperAdmin);
        $submitter = $this->operationalUser(UserRole::AdminOperational);
        User::factory()->create([
            'name' => 'Manager Pembelian HO',
            'role' => UserRole::ManagerAdminHo,
            'is_active' => true,
        ]);

        $response = $this->withoutVite()->actingAs($admin)->get(route('admin.approval-workflows.create'));

        $response->assertOk()
            ->assertSee($submitter->name.' — DMI-01')
            ->assertDontSee('Pool / Wilayah')
            ->assertSee('Manager Pembelian HO — Manager Admin HO')
            ->assertSee('name="steps[0][approver_id]"', false)
            ->assertSee('name="steps[1][approver_id]"', false);
    }

    public function test_admin_can_create_and_reorder_a_document_approval_workflow(): void
    {
        $admin = $this->operationalUser(UserRole::SuperAdmin);
        $submitter = $this->operationalUser(UserRole::AdminOperational);
        $firstApprover = User::factory()->create(['role' => UserRole::ManagerOperational]);
        $secondApprover = User::factory()->create(['role' => UserRole::ManagerAdminHo]);
        UserOperationalProfile::query()->create([
            'user_id' => $firstApprover->id,
            'pool_id' => $submitter->operationalProfile->pool_id,
            'department_id' => $submitter->operationalProfile->department_id,
            'warehouse_code' => 'DMI-01',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.approval-workflows.store'), [
            'document_type' => 'PURCHASE_REQUEST',
            'submitter_id' => $submitter->id,
            'name' => 'Approval PR Operasional',
            'is_active' => '1',
            'steps' => [
                ['approver_id' => $firstApprover->id],
                ['approver_id' => $secondApprover->id],
            ],
        ]);

        $response->assertRedirect(route('admin.approval-workflows.index'));
        $workflow = ApprovalWorkflow::query()->with('steps')->firstOrFail();
        $this->assertSame($submitter->id, $workflow->submitter_id);
        $this->assertSame([$firstApprover->id, $secondApprover->id], $workflow->steps->pluck('approver_id')->all());
        $this->assertSame([$firstApprover->email, $secondApprover->email], $workflow->steps->pluck('notification_email')->all());

        $this->actingAs($admin)->put(route('admin.approval-workflows.update', $workflow), [
            'document_type' => 'PURCHASE_REQUEST',
            'submitter_id' => $submitter->id,
            'name' => 'Approval PR Revisi',
            'is_active' => '1',
            'steps' => [
                ['approver_id' => $secondApprover->id],
                ['approver_id' => $firstApprover->id],
            ],
        ])->assertRedirect(route('admin.approval-workflows.index'));

        $this->assertSame([$secondApprover->id, $firstApprover->id], $workflow->fresh()->steps->pluck('approver_id')->all());
        $this->assertSame([$secondApprover->email, $firstApprover->email], $workflow->fresh()->steps->pluck('notification_email')->all());
    }

    public function test_non_admin_cannot_manage_approval_workflows(): void
    {
        $user = $this->operationalUser(UserRole::AdminOperational);

        $this->actingAs($user)->get(route('admin.approval-workflows.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.approval-workflows.store'), [])->assertForbidden();
    }

    public function test_workflow_submitter_must_be_an_active_admin_operational_user(): void
    {
        $admin = $this->operationalUser(UserRole::SuperAdmin);
        $manager = $this->operationalUser(UserRole::ManagerOperational);
        $approver = User::factory()->create(['role' => UserRole::ManagerAdminHo]);

        $this->from(route('admin.approval-workflows.create'))
            ->actingAs($admin)
            ->post(route('admin.approval-workflows.store'), [
                'document_type' => 'PURCHASE_REQUEST',
                'submitter_id' => $manager->id,
                'name' => 'Workflow Tidak Valid',
                'is_active' => '1',
                'steps' => [['approver_id' => $approver->id]],
            ])
            ->assertRedirect(route('admin.approval-workflows.create'))
            ->assertSessionHasErrors('submitter_id');

        $this->assertDatabaseMissing('approval_workflows', ['name' => 'Workflow Tidak Valid']);
    }

    public function test_purchase_request_rejects_a_manager_from_another_warehouse(): void
    {
        $admin = $this->operationalUser(UserRole::SuperAdmin);
        $submitter = $this->operationalUser(UserRole::AdminOperational);
        $otherPool = ControlPool::query()->create(['code' => 'MEDAN', 'name' => 'Medan', 'is_active' => true]);
        $otherDepartment = ControlDepartment::query()->create([
            'pool_id' => $otherPool->id,
            'external_id' => fake()->uuid(),
            'code' => 'OPS',
            'name' => 'Operasional',
            'is_active' => true,
        ]);
        $otherManager = User::factory()->create(['role' => UserRole::ManagerOperational]);
        UserOperationalProfile::query()->create([
            'user_id' => $otherManager->id,
            'pool_id' => $otherPool->id,
            'department_id' => $otherDepartment->id,
            'warehouse_code' => 'MDN-01',
        ]);

        $this->actingAs($admin)->post(route('admin.approval-workflows.store'), [
            'document_type' => 'PURCHASE_REQUEST',
            'submitter_id' => $submitter->id,
            'name' => 'Approval PR Dumai',
            'is_active' => '1',
            'steps' => [['approver_id' => $otherManager->id]],
        ])->assertSessionHasErrors('steps.0.approver_id');

        $this->assertDatabaseMissing('approval_workflows', ['name' => 'Approval PR Dumai']);
    }

    private function operationalUser(UserRole $role): User
    {
        $pool = ControlPool::query()->create(['code' => 'DUMAI-'.fake()->unique()->numerify('###'), 'name' => 'Dumai', 'is_active' => true]);
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

        return $user;
    }
}
