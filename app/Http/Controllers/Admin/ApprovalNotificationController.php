<?php

namespace App\Http\Controllers\Admin;

use App\Application\Approvals\QueueApprovalRequestEmail;
use App\Exceptions\SapSubmissionException;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\DocumentApprovalStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApprovalNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentApprovalStep $step,
        SapCompanyContext $companies,
        QueueApprovalRequestEmail $queueApprovalRequestEmail,
    ): RedirectResponse {
        $step->loadMissing('approval.document');
        abort_unless($step->approval->document->company_db === $companies->current(), 404);

        try {
            $queueApprovalRequestEmail->handle($step, renewLink: true);

            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'event' => 'approval.email_queued_manually',
                'auditable_type' => $step->approval->document::class,
                'auditable_id' => $step->approval->sap_document_id,
                'metadata' => ['step_order' => $step->step_order, 'recipient' => $step->notification_email],
                'ip_address' => $request->ip(),
            ]);

            return back()->with('status', "Email approval telah dimasukkan ke antrean untuk {$step->notification_email}.");
        } catch (SapSubmissionException $exception) {
            return back()->withErrors(['approval' => $exception->getMessage()]);
        }
    }
}
