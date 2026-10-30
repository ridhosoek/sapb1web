<?php

namespace App\Application\Approvals;

use App\Exceptions\SapSubmissionException;
use App\Models\ApprovalWorkflow;
use App\Models\DocumentApproval;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StartDocumentApproval
{
    public function __construct(
        private readonly QueueApprovalRequestEmail $queueApprovalRequestEmail,
    ) {}

    public function handle(SapDocument $document, User $submitter): DocumentApproval
    {
        $workflow = ApprovalWorkflow::query()
            ->where('company_db', $document->company_db)
            ->whereBelongsTo($submitter, 'submitter')
            ->where('document_type', $document->document_type)
            ->where('is_active', true)
            ->with(['steps.approver'])
            ->first();

        if (! $workflow || $workflow->steps->isEmpty()) {
            $label = $document->document_type === 'PURCHASE_REQUEST' ? 'Purchase Request' : 'Goods Issue';

            throw new SapSubmissionException("Workflow approval {$label} untuk user {$submitter->name} pada database {$document->company_db} belum dikonfigurasi.");
        }

        $approval = DB::transaction(function () use ($document, $submitter, $workflow): DocumentApproval {
            $approval = DocumentApproval::query()->create([
                'sap_document_id' => $document->id,
                'approval_workflow_id' => $workflow->id,
                'submitted_by' => $submitter->id,
                'status' => 'PENDING',
                'current_step' => 1,
                'submitted_at' => now(),
            ]);

            foreach ($workflow->steps as $workflowStep) {
                $approval->steps()->create([
                    'approver_id' => $workflowStep->approver_id,
                    'approver_name' => $workflowStep->approver->name,
                    'notification_email' => $document->document_type === 'PURCHASE_REQUEST'
                        ? ($workflowStep->notification_email ?: $workflowStep->approver->email)
                        : null,
                    'step_order' => $workflowStep->step_order,
                    'status' => 'PENDING',
                    'token' => (string) Str::uuid(),
                    'expires_at' => now()->addHours((int) config('approval.link_expiration_hours', 48)),
                ]);
            }

            return $approval->load(['steps.approver', 'submitter']);
        });

        $firstStep = $approval->steps->firstWhere('step_order', 1);

        if ($firstStep && $document->document_type === 'PURCHASE_REQUEST') {
            $this->queueApprovalRequestEmail->handle($firstStep);
        }

        return $approval;
    }
}
