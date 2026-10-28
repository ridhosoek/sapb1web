<?php

namespace App\Application\Approvals;

use App\Exceptions\SapSubmissionException;
use App\Models\AuditLog;
use App\Models\DocumentApproval;
use App\Models\DocumentApprovalStep;
use Illuminate\Support\Facades\DB;

class DecideDocumentApproval
{
    public function __construct(
        private readonly PostApprovedDocument $postApprovedDocument,
        private readonly QueueApprovalRequestEmail $queueApprovalRequestEmail,
    ) {}

    public function handle(DocumentApprovalStep $approvalStep, string $decision, ?string $note = null, ?string $ipAddress = null): DocumentApproval
    {
        $shouldPost = false;
        $nextStepId = null;
        $approval = DB::transaction(function () use ($approvalStep, $decision, $note, $ipAddress, &$shouldPost, &$nextStepId): DocumentApproval {
            $step = DocumentApprovalStep::query()->lockForUpdate()->findOrFail($approvalStep->id);
            $approval = DocumentApproval::query()->lockForUpdate()->with('document')->findOrFail($step->document_approval_id);

            if ($approval->status !== 'PENDING' || $step->status !== 'PENDING') {
                throw new SapSubmissionException('Tautan approval ini sudah pernah diproses.');
            }

            if ($approval->current_step !== $step->step_order) {
                throw new SapSubmissionException('Approval belum berada pada urutan approver ini.');
            }

            $isApproved = $decision === 'APPROVE';
            $step->update([
                'status' => $isApproved ? 'APPROVED' : 'REJECTED',
                'decided_at' => now(),
                'decision_note' => $note,
                'decision_ip' => $ipAddress,
            ]);

            if (! $isApproved) {
                $approval->update(['status' => 'REJECTED', 'decided_at' => now()]);
                $this->updateDocumentApprovalStatus($approval, 'REJECTED');
            } else {
                $nextStep = $approval->steps()->where('step_order', '>', $step->step_order)->orderBy('step_order')->first();

                if ($nextStep) {
                    $nextStep->update(['expires_at' => now()->addHours((int) config('approval.link_expiration_hours', 48))]);
                    $approval->update(['current_step' => $nextStep->step_order]);
                    $nextStepId = $nextStep->id;
                } else {
                    $approval->update(['status' => 'APPROVED', 'decided_at' => now()]);
                    $this->updateDocumentApprovalStatus($approval, 'APPROVED');
                    $shouldPost = true;
                }
            }

            AuditLog::query()->create([
                'actor_id' => $step->approver_id,
                'event' => $isApproved ? 'document.approved' : 'document.rejected',
                'auditable_type' => $approval->document::class,
                'auditable_id' => $approval->sap_document_id,
                'metadata' => ['step_order' => $step->step_order, 'note' => $note],
                'ip_address' => $ipAddress,
            ]);

            return $approval;
        });

        if ($nextStepId) {
            $nextStep = DocumentApprovalStep::query()->with(['approver', 'approval'])->findOrFail($nextStepId);
            $this->queueApprovalRequestEmail->handle($nextStep);
        }

        if ($shouldPost) {
            $this->postApprovedDocument->handle($approval->document, $approvalStep->approver);
        }

        return $approval->fresh(['document', 'steps.approver', 'submitter']);
    }

    private function updateDocumentApprovalStatus(DocumentApproval $approval, string $status): void
    {
        $data = $approval->document->normalized_data ?? [];
        $data['approval_status'] = $status;
        $approval->document->update(['normalized_data' => $data]);
    }
}
