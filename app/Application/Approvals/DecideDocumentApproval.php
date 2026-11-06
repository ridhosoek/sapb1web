<?php

namespace App\Application\Approvals;

use App\Application\Inventory\GoodsIssueFpkDateWindow;
use App\Exceptions\SapSubmissionException;
use App\Models\AuditLog;
use App\Models\DocumentApproval;
use App\Models\DocumentApprovalStep;
use App\Models\SapCommand;
use Illuminate\Support\Facades\DB;

class DecideDocumentApproval
{
    public const FPK_DATE_EXPIRED = 'FPK_DATE_EXPIRED';

    public function __construct(
        private readonly PostApprovedDocument $postApprovedDocument,
        private readonly QueueApprovalRequestEmail $queueApprovalRequestEmail,
        private readonly GoodsIssueFpkDateWindow $fpkDates,
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

            if ($isApproved && $this->hasExpiredGoodsIssueFpkDate($approval, $step)) {
                $this->rejectForExpiredFpkDate($approval, $step, $note, $ipAddress);

                return $approval;
            }

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

        if ($nextStepId && $approval->document->document_type === 'PURCHASE_REQUEST') {
            $nextStep = DocumentApprovalStep::query()->with(['approver', 'approval'])->findOrFail($nextStepId);
            $this->queueApprovalRequestEmail->handle($nextStep);
        }

        if ($shouldPost) {
            $this->postApprovedDocument->handle($approval->document, $approvalStep->approver);
        }

        return $approval->fresh(['document', 'steps.approver', 'submitter']);
    }

    /**
     * Pada approval final, tanggal FPK Goods Issue harus masih hari ini atau H-1.
     */
    private function hasExpiredGoodsIssueFpkDate(DocumentApproval $approval, DocumentApprovalStep $step): bool
    {
        $isFinalStep = ! $approval->steps()->where('step_order', '>', $step->step_order)->exists();

        return $isFinalStep
            && $approval->document->document_type === 'GOODS_ISSUE'
            && ! $this->fpkDates->allows(data_get($approval->document->normalized_data, 'fpk_date'));
    }

    private function rejectForExpiredFpkDate(DocumentApproval $approval, DocumentApprovalStep $step, ?string $note, ?string $ipAddress): void
    {
        $fpkDate = (string) data_get($approval->document->normalized_data, 'fpk_date');
        $message = "Goods Issue ditolak otomatis: tanggal FPK {$fpkDate} sudah lebih tua dari H-1 saat approval final (batas terawal {$this->fpkDates->earliest()}). Ajukan ulang dengan FPK fisik baru, nomor FPK baru, dan tanggal FPK baru. Nomor dan tanggal FPK lama tidak dapat dipakai ulang.";
        $step->update([
            'status' => 'REJECTED',
            'decided_at' => now(),
            'decision_note' => trim(($note !== null && $note !== '' ? $note."\n" : '').$message),
            'decision_ip' => $ipAddress,
        ]);
        $approval->update(['status' => 'REJECTED', 'decided_at' => now()]);

        $data = $approval->document->normalized_data ?? [];
        $data['approval_status'] = 'REJECTED';
        $data['approval_rejection_reason'] = self::FPK_DATE_EXPIRED;
        $data['approval_rejected_automatically'] = true;
        $data['approval_rejection_message'] = $message;
        $approval->document->update(['normalized_data' => $data]);

        SapCommand::query()
            ->where('transaction_uuid', $approval->document->transaction_uuid)
            ->update(['last_error' => $message]);
        AuditLog::query()->create([
            'actor_id' => $step->approver_id,
            'event' => 'goods_issue.rejected_expired_fpk_date',
            'auditable_type' => $approval->document::class,
            'auditable_id' => $approval->sap_document_id,
            'metadata' => [
                'company_db' => $approval->document->company_db,
                'step_order' => $step->step_order,
                'fpk_number' => $approval->document->fpk_number,
                'fpk_date' => $fpkDate,
                'earliest_allowed_date' => $this->fpkDates->earliest(),
                'latest_allowed_date' => $this->fpkDates->latest(),
                'automatic' => true,
                'note' => $note,
            ],
            'ip_address' => $ipAddress,
        ]);
    }

    private function updateDocumentApprovalStatus(DocumentApproval $approval, string $status): void
    {
        $data = $approval->document->normalized_data ?? [];
        $data['approval_status'] = $status;
        $approval->document->update(['normalized_data' => $data]);
    }
}
