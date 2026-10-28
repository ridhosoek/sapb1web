<?php

namespace App\Jobs;

use App\Mail\ApprovalRequestedMail;
use App\Models\DocumentApprovalStep;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendApprovalRequestEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $approvalStepId) {}

    public function uniqueId(): string
    {
        return (string) $this->approvalStepId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $step = DocumentApprovalStep::query()
            ->with(['approver', 'approval.document.pool', 'approval.document.department', 'approval.submitter', 'approval.steps'])
            ->find($this->approvalStepId);

        if (! $step
            || $step->approval->document->document_type !== 'PURCHASE_REQUEST'
            || ! $this->isCurrentPendingStep($step)) {
            return;
        }

        $step->increment('notification_attempts');

        try {
            Mail::to($step->notification_email)->send(new ApprovalRequestedMail($step));
        } catch (Throwable $exception) {
            $step->update([
                'notification_failed_at' => now(),
                'notification_error' => Str::limit($exception->getMessage(), 1000),
            ]);

            throw $exception;
        }

        $step->update([
            'notification_sent_at' => now(),
            'notification_failed_at' => null,
            'notification_error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        DocumentApprovalStep::query()->whereKey($this->approvalStepId)->update([
            'notification_failed_at' => now(),
            'notification_error' => Str::limit($exception?->getMessage() ?? 'Pengiriman email gagal.', 1000),
        ]);
    }

    private function isCurrentPendingStep(DocumentApprovalStep $step): bool
    {
        return $step->status === 'PENDING'
            && $step->approval->status === 'PENDING'
            && $step->approval->current_step === $step->step_order;
    }
}
