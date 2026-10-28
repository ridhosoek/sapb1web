<?php

namespace App\Application\Approvals;

use App\Exceptions\SapSubmissionException;
use App\Jobs\SendApprovalRequestEmail;
use App\Models\DocumentApprovalStep;

class QueueApprovalRequestEmail
{
    public function handle(DocumentApprovalStep $step, bool $renewLink = false): void
    {
        $step->loadMissing(['approver', 'approval']);

        if ($step->status !== 'PENDING'
            || $step->approval->status !== 'PENDING'
            || $step->approval->current_step !== $step->step_order) {
            throw new SapSubmissionException('Email hanya dapat dikirim untuk tahap approval yang sedang aktif.');
        }

        $email = $step->notification_email ?: $step->approver->email;

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SapSubmissionException("Email approver {$step->approver_name} belum valid.");
        }

        $changes = [
            'notification_email' => $email,
            'notification_queued_at' => now(),
            'notification_failed_at' => null,
            'notification_error' => null,
        ];

        if ($renewLink || $step->expires_at->isPast()) {
            $changes['expires_at'] = now()->addHours((int) config('approval.link_expiration_hours', 48));
        }

        $step->update($changes);

        SendApprovalRequestEmail::dispatch($step->getKey())->afterCommit();
    }
}
