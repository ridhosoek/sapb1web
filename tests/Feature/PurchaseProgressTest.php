<?php

namespace Tests\Feature;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\SapDocumentRelation;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PurchaseProgressTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_purchase_request_only_marks_pr_as_completed(): void
    {
        [$user, $pool, $department] = $this->operationalContext();
        SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_entry' => 1426,
            'sap_doc_num' => '260001426',
            'source' => 'WEB',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'created_from_web' => true,
            'normalized_data' => ['progress_status' => 'WAITING_QUOTATION'],
        ]);

        $response = $this->withoutVite()
            ->actingAs($user)
            ->get(route('purchase-progress.index'));

        $response->assertOk()
            ->assertSee('data-progress-step="pr" data-progress-state="done"', false)
            ->assertSee('data-progress-step="quotation" data-progress-state="current"', false)
            ->assertSee('data-progress-step="po" data-progress-state="pending"', false)
            ->assertSee('data-progress-step="grpo" data-progress-state="pending"', false)
            ->assertDontSee('data-progress-step="quotation" data-progress-state="done"', false);
    }

    public function test_legacy_waiting_po_without_a_watcher_does_not_fake_a_completed_quotation(): void
    {
        [$user, $pool, $department] = $this->operationalContext();
        SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_entry' => 1427,
            'sap_doc_num' => '260001427',
            'source' => 'WEB',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'created_from_web' => true,
            'normalized_data' => ['progress_status' => 'WAITING_PO'],
        ]);

        $response = $this->withoutVite()
            ->actingAs($user)
            ->get(route('purchase-progress.index'));

        $response->assertOk()
            ->assertSee('data-progress-step="pr" data-progress-state="done"', false)
            ->assertSee('data-progress-step="quotation" data-progress-state="current"', false)
            ->assertDontSee('data-progress-step="quotation" data-progress-state="done"', false);
    }

    public function test_purchase_quotation_relation_marks_quotation_as_completed(): void
    {
        [$user, $pool, $department] = $this->operationalContext();
        $purchaseRequest = SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'sap_doc_entry' => 1428,
            'sap_doc_num' => '260001428',
            'source' => 'WEB',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'created_from_web' => true,
            'normalized_data' => ['progress_status' => 'WAITING_PO'],
        ]);
        $purchaseQuotation = SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'document_type' => 'PURCHASE_QUOTATION',
            'sap_entity' => 'PurchaseQuotations',
            'sap_doc_entry' => 501,
            'sap_doc_num' => '260000501',
            'source' => 'SAP',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'created_from_web' => false,
        ]);
        SapDocumentRelation::query()->create([
            'parent_document_id' => $purchaseRequest->id,
            'child_document_id' => $purchaseQuotation->id,
            'relation_type' => 'PURCHASE_QUOTATION',
            'discovered_by' => 'SERVICE_LAYER_BASE_DOCUMENT',
        ]);

        $response = $this->withoutVite()
            ->actingAs($user)
            ->get(route('purchase-progress.index'));

        $response->assertOk()
            ->assertSee('data-progress-step="quotation" data-progress-state="done"', false)
            ->assertSee('data-progress-step="po" data-progress-state="current"', false)
            ->assertSee('Sync SAP');
    }

    /** @return array{User, ControlPool, ControlDepartment} */
    private function operationalContext(): array
    {
        $pool = ControlPool::query()->create([
            'code' => 'DUMAI',
            'name' => 'Dumai',
            'is_active' => true,
        ]);
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

        return [$user, $pool, $department];
    }
}
