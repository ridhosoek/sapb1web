<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\DocumentApproval;
use App\Models\SapDocument;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PurchaseRequestCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
    }

    public function test_admin_ho_can_check_a_purchase_request_once(): void
    {
        [$adminHo] = $this->operationalUser(UserRole::AdminHo, 'HO');
        [$submitter, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseRequest = $this->purchaseRequest($submitter, $pool, $department);

        $this->withoutVite()
            ->actingAs($adminHo)
            ->get(route('documents.show', $purchaseRequest))
            ->assertOk()
            ->assertSee('Check PR</button>', false)
            ->assertSee('Belum diperiksa Admin HO.');

        $this->actingAs($adminHo)
            ->post(route('purchase-requests.check', $purchaseRequest))
            ->assertRedirect(route('documents.show', $purchaseRequest))
            ->assertSessionHas('status', 'Purchase Request sudah diperiksa oleh Admin HO. Nama dan waktu check akan tampil pada cetakan.');

        $purchaseRequest->refresh();
        $this->assertSame($adminHo->id, $purchaseRequest->admin_ho_checked_by);
        $this->assertNotNull($purchaseRequest->admin_ho_checked_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $adminHo->id,
            'event' => 'purchase_request.checked_by_admin_ho',
            'auditable_type' => SapDocument::class,
            'auditable_id' => $purchaseRequest->id,
        ]);

        $this->withoutVite()
            ->actingAs($adminHo)
            ->get(route('documents.show', $purchaseRequest))
            ->assertOk()
            ->assertSee('Check Admin HO:')
            ->assertSee($adminHo->name)
            ->assertDontSee('Check PR</button>', false);

        $this->actingAs($adminHo)
            ->post(route('purchase-requests.check', $purchaseRequest))
            ->assertForbidden();
    }

    public function test_operational_user_cannot_check_a_purchase_request(): void
    {
        [$submitter, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $purchaseRequest = $this->purchaseRequest($submitter, $pool, $department);

        $this->actingAs($submitter)
            ->post(route('purchase-requests.check', $purchaseRequest))
            ->assertForbidden();

        $this->assertNull($purchaseRequest->refresh()->admin_ho_checked_by);
    }

    public function test_admin_ho_cannot_check_rejected_or_other_company_purchase_requests(): void
    {
        [$adminHo] = $this->operationalUser(UserRole::AdminHo, 'HO');
        [$submitter, $pool, $department] = $this->operationalUser(UserRole::AdminOperational, 'DUMAI');
        $rejectedPurchaseRequest = $this->purchaseRequest($submitter, $pool, $department, 'TEST_DB', 'REJECTED');
        $otherCompanyPurchaseRequest = $this->purchaseRequest($submitter, $pool, $department, 'SECOND_DB');

        $this->actingAs($adminHo)
            ->post(route('purchase-requests.check', $rejectedPurchaseRequest))
            ->assertForbidden();
        $this->actingAs($adminHo)
            ->post(route('purchase-requests.check', $otherCompanyPurchaseRequest))
            ->assertNotFound();
    }

    /** @return array{User, ControlPool, ControlDepartment} */
    private function operationalUser(UserRole $role, string $scope): array
    {
        $suffix = strtolower($scope).'-'.fake()->unique()->numerify('###');
        $pool = ControlPool::query()->create([
            'code' => 'POOL-'.$suffix,
            'name' => 'Pool '.$scope,
            'is_active' => true,
        ]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-'.$suffix,
            'code' => 'TSP-'.$suffix,
            'name' => 'Transport '.$scope,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => strtoupper($scope).'-01',
        ]);

        return [$user, $pool, $department];
    }

    private function purchaseRequest(
        User $submitter,
        ControlPool $pool,
        ControlDepartment $department,
        string $companyDb = 'TEST_DB',
        string $approvalStatus = 'PENDING',
    ): SapDocument {
        $purchaseRequest = SapDocument::factory()->create([
            'company_db' => $companyDb,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'normalized_data' => [
                'warehouse_code' => $submitter->operationalProfile->warehouse_code,
                'lines' => [],
            ],
        ]);
        DocumentApproval::query()->create([
            'sap_document_id' => $purchaseRequest->id,
            'approval_workflow_id' => null,
            'submitted_by' => $submitter->id,
            'status' => $approvalStatus,
            'current_step' => 1,
            'submitted_at' => now(),
            'decided_at' => $approvalStatus === 'PENDING' ? null : now(),
        ]);

        return $purchaseRequest;
    }
}
