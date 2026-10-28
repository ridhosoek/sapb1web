<?php

namespace Tests\Feature;

use App\Models\ApprovalWorkflow;
use App\Models\DocumentApproval;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_approval_page_requires_a_valid_signed_one_time_link(): void
    {
        $approver = User::factory()->create();
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => 'TEST_DB',
            'document_type' => 'PURCHASE_REQUEST',
            'name' => 'Approval PR',
            'is_active' => true,
        ]);
        $document = SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'source' => 'WEB',
            'transaction_uuid' => (string) Str::uuid(),
            'sap_business_status' => 'OPEN',
            'integration_status' => 'PENDING_POST',
            'normalized_data' => [
                'ending_km' => 123456,
                'lines' => [[
                    'item_code' => 'I05358',
                    'item_name' => 'Baterai GS NS 70',
                    'quantity' => 1,
                    'usage_history' => [
                        'status' => 'FOUND',
                        'last_goods_issue_date' => '2026-10-01',
                        'expense_amount' => 425000,
                        'days_in_use' => 21,
                    ],
                ]],
            ],
        ]);
        $approval = DocumentApproval::query()->create([
            'sap_document_id' => $document->id,
            'approval_workflow_id' => $workflow->id,
            'submitted_by' => $approver->id,
            'status' => 'PENDING',
            'current_step' => 1,
            'submitted_at' => now(),
        ]);
        $step = $approval->steps()->create([
            'approver_id' => $approver->id,
            'approver_name' => $approver->name,
            'step_order' => 1,
            'status' => 'PENDING',
            'token' => (string) Str::uuid(),
            'expires_at' => now()->addHour(),
        ]);

        $this->get(route('approvals.show', ['step' => $step, 'token' => $step->token]))->assertForbidden();
        $this->withoutVite()->get($step->approvalUrl())
            ->assertOk()
            ->assertSee('Setujui')
            ->assertSee('KM Akhir')
            ->assertSee('I05358')
            ->assertSee('425.000')
            ->assertSee('21 hari');
        $this->withoutVite()->get(str_replace($step->token, (string) Str::uuid(), $step->approvalUrl()))->assertForbidden();
    }
}
