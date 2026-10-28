<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

#[Fillable(['document_approval_id', 'approver_id', 'approver_name', 'notification_email', 'step_order', 'status', 'token', 'expires_at', 'decided_at', 'decision_note', 'decision_ip', 'notification_queued_at', 'notification_sent_at', 'notification_failed_at', 'notification_attempts', 'notification_error'])]
class DocumentApprovalStep extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'notification_queued_at' => 'datetime',
            'notification_sent_at' => 'datetime',
            'notification_failed_at' => 'datetime',
            'notification_attempts' => 'integer',
        ];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(DocumentApproval::class, 'document_approval_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function approvalUrl(): string
    {
        return URL::temporarySignedRoute('approvals.show', $this->expires_at, [
            'step' => $this->getKey(),
            'token' => $this->token,
        ]);
    }
}
