<?php

namespace App\Http\Controllers;

use App\Application\Approvals\DecideDocumentApproval;
use App\Exceptions\SapSubmissionException;
use App\Models\DocumentApprovalStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ApprovalDecisionController extends Controller
{
    public function show(Request $request, DocumentApprovalStep $step, string $token): View
    {
        abort_unless(hash_equals($step->token, $token), 404);
        $step->load(['approval.document.pool', 'approval.document.department', 'approval.submitter', 'approval.steps.approver']);
        abort_unless($step->approval->document->document_type === 'PURCHASE_REQUEST', 404);

        return view('approvals.show', [
            'step' => $step,
            'approval' => $step->approval,
            'document' => $step->approval->document,
            'isCurrentStep' => $step->approval->status === 'PENDING'
                && $step->status === 'PENDING'
                && $step->approval->current_step === $step->step_order,
        ]);
    }

    public function decide(Request $request, DocumentApprovalStep $step, string $token, DecideDocumentApproval $decide): RedirectResponse
    {
        abort_unless(hash_equals($step->token, $token), 404);
        $step->loadMissing('approval.document');
        abort_unless($step->approval->document->document_type === 'PURCHASE_REQUEST', 404);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->input('decision') === 'REJECT')],
        ]);

        try {
            $decide->handle($step->load('approver'), $validated['decision'], $validated['note'] ?? null, $request->ip());

            return redirect()->to($step->fresh()->approvalUrl())->with('status', $validated['decision'] === 'APPROVE'
                ? 'Persetujuan berhasil dicatat.'
                : 'Penolakan berhasil dicatat.');
        } catch (SapSubmissionException $exception) {
            return redirect()->to($step->approvalUrl())->withErrors(['approval' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->to($step->approvalUrl())->with('warning', 'Approval tercatat, tetapi pengiriman ke SAP belum berhasil. Admin dapat menindaklanjuti dari Integration Monitor.');
        }
    }
}
