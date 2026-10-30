<?php

namespace Tests\Feature;

use App\Application\Approvals\StartDocumentApproval;
use App\Jobs\SendApprovalRequestEmail;
use App\Mail\ApprovalRequestedMail;
use App\Models\ApprovalWorkflow;
use App\Models\ControlPool;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_first_approver_email_is_queued_and_contains_a_secure_review_link(): void
    {
        Queue::fake();
        Mail::fake();
        [$document, $submitter, $firstApprover] = $this->documentWithWorkflow();

        $approval = app(StartDocumentApproval::class)->handle($document, $submitter);
        $step = $approval->steps()->firstOrFail();

        Queue::assertPushed(SendApprovalRequestEmail::class, fn (SendApprovalRequestEmail $job): bool => $job->approvalStepId === $step->id);
        $this->assertSame($firstApprover->email, $step->fresh()->notification_email);
        $this->assertNotNull($step->fresh()->notification_queued_at);

        (new SendApprovalRequestEmail($step->id))->handle();

        Mail::assertSent(ApprovalRequestedMail::class, function (ApprovalRequestedMail $mail) use ($firstApprover): bool {
            return $mail->hasTo($firstApprover->email)
                && $mail->assertSeeInHtml('Tinjau dan Beri Keputusan') === $mail
                && $mail->assertSeeInHtml('Purchase Request') === $mail
                && $mail->assertSeeInHtml('Detail Item') === $mail
                && $mail->assertSeeInOrderInHtml(['Kode', 'Nama Item', 'Qty', 'I02042', 'Baterai GS NS 70', '2', 'I05358', 'Oli Mesin', '1,5']) === $mail
                && $mail->assertSeeInText('I02042') === $mail
                && $mail->assertSeeInText('I05358') === $mail;
        });
        $this->assertNotNull($step->fresh()->notification_sent_at);
        $this->assertSame(1, $step->fresh()->notification_attempts);
    }

    public function test_approving_a_step_queues_email_only_for_the_next_approver(): void
    {
        Queue::fake();
        [$document, $submitter] = $this->documentWithWorkflow();
        $approval = app(StartDocumentApproval::class)->handle($document, $submitter);
        $steps = $approval->steps()->orderBy('step_order')->get();
        Queue::fake();

        $this->post($steps[0]->approvalUrl(), ['decision' => 'APPROVE'])->assertRedirect();

        Queue::assertPushed(SendApprovalRequestEmail::class, 1);
        Queue::assertPushed(SendApprovalRequestEmail::class, fn (SendApprovalRequestEmail $job): bool => $job->approvalStepId === $steps[1]->id);
        $this->assertNotNull($steps[1]->fresh()->notification_queued_at);
        $this->assertNull($steps[0]->fresh()->notification_sent_at);
    }

    public function test_approval_email_escapes_item_names(): void
    {
        Queue::fake();
        [$document, $submitter] = $this->documentWithWorkflow();
        $data = $document->normalized_data;
        $data['lines'][0]['item_name'] = '<script>alert("approval")</script>';
        $document->update(['normalized_data' => $data]);
        $approval = app(StartDocumentApproval::class)->handle($document, $submitter);
        $mail = new ApprovalRequestedMail($approval->steps()->firstOrFail());

        $mail->assertSeeInHtml('&lt;script&gt;', false)
            ->assertDontSeeInHtml('<script>alert("approval")</script>', false);
    }

    public function test_goods_issue_approval_is_kept_inside_the_portal_without_sending_email(): void
    {
        Queue::fake();
        Mail::fake();
        [$document, $submitter] = $this->documentWithWorkflow();
        $document->update(['document_type' => 'GOODS_ISSUE', 'sap_entity' => 'InventoryGenExits']);
        ApprovalWorkflow::query()->where('document_type', 'PURCHASE_REQUEST')->update(['document_type' => 'GOODS_ISSUE']);

        $approval = app(StartDocumentApproval::class)->handle($document->fresh(), $submitter);
        $step = $approval->steps()->firstOrFail();

        Queue::assertNotPushed(SendApprovalRequestEmail::class);
        $this->assertNull($step->notification_email);
        $this->assertNull($step->notification_queued_at);

        (new SendApprovalRequestEmail($step->id))->handle();

        Mail::assertNothingSent();
        $this->assertNull($step->fresh()->notification_sent_at);
    }

    public function test_document_approval_uses_the_workflow_for_its_submitter(): void
    {
        Queue::fake();
        $dumai = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $medan = ControlPool::query()->create(['code' => 'MEDAN', 'name' => 'Medan', 'is_active' => true]);
        $dumaiSubmitter = User::factory()->create();
        $medanSubmitter = User::factory()->create();
        $dumaiApprover = User::factory()->create(['email' => 'manager.dumai@example.test']);
        $medanApprover = User::factory()->create(['email' => 'manager.medan@example.test']);

        foreach ([[$dumai, $dumaiSubmitter, $dumaiApprover], [$medan, $medanSubmitter, $medanApprover]] as [$pool, $submitter, $approver]) {
            $workflow = ApprovalWorkflow::query()->create([
                'company_db' => 'TEST_DB',
                'submitter_id' => $submitter->id,
                'pool_id' => $pool->id,
                'document_type' => 'PURCHASE_REQUEST',
                'name' => 'Approval PR '.$pool->code,
                'is_active' => true,
            ]);
            $workflow->steps()->create([
                'approver_id' => $approver->id,
                'step_order' => 1,
                'notification_email' => $approver->email,
            ]);
        }

        $document = SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $medan->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'transaction_uuid' => (string) Str::uuid(),
        ]);

        $approval = app(StartDocumentApproval::class)->handle($document, $medanSubmitter);

        $this->assertSame($medanApprover->id, $approval->steps()->firstOrFail()->approver_id);
        Queue::assertPushed(SendApprovalRequestEmail::class, 1);
    }

    /** @return array{SapDocument, User, User} */
    private function documentWithWorkflow(): array
    {
        $submitter = User::factory()->create(['name' => 'Admin Operasional']);
        $firstApprover = User::factory()->create(['name' => 'Supervisor Operasional']);
        $secondApprover = User::factory()->create(['name' => 'Admin HO']);
        $pool = ControlPool::query()->create(['code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]);
        $workflow = ApprovalWorkflow::query()->create([
            'company_db' => 'TEST_DB',
            'submitter_id' => $submitter->id,
            'pool_id' => $pool->id,
            'document_type' => 'PURCHASE_REQUEST',
            'name' => 'Approval PR',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'approver_id' => $firstApprover->id,
            'step_order' => 1,
            'notification_email' => $firstApprover->email,
        ]);
        $workflow->steps()->create([
            'approver_id' => $secondApprover->id,
            'step_order' => 2,
            'notification_email' => $secondApprover->email,
        ]);
        $document = SapDocument::query()->create([
            'company_db' => 'TEST_DB',
            'pool_id' => $pool->id,
            'document_type' => 'PURCHASE_REQUEST',
            'sap_entity' => 'PurchaseRequests',
            'source' => 'WEB',
            'transaction_uuid' => (string) Str::uuid(),
            'sap_business_status' => 'OPEN',
            'integration_status' => 'PENDING_POST',
            'normalized_data' => [
                'ending_km' => 125000,
                'vehicle_cost_center' => ['center_code' => 'BK8815FG'],
                'lines' => [
                    ['item_code' => 'I02042', 'item_name' => 'Baterai GS NS 70', 'quantity' => 2],
                    ['item_code' => 'I05358', 'item_name' => 'Oli Mesin', 'quantity' => 1.5],
                ],
            ],
        ]);

        return [$document, $submitter, $firstApprover];
    }
}
