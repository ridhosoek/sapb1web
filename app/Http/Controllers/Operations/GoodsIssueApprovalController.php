<?php

namespace App\Http\Controllers\Operations;

use App\Application\Approvals\DecideDocumentApproval;
use App\Exceptions\SapSubmissionException;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\DocumentApprovalStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class GoodsIssueApprovalController extends Controller
{
    public function index(Request $request, SapCompanyContext $companies): View
    {
        $steps = DocumentApprovalStep::query()
            ->select('document_approval_steps.*')
            ->join('document_approvals', 'document_approvals.id', '=', 'document_approval_steps.document_approval_id')
            ->join('sap_documents', 'sap_documents.id', '=', 'document_approvals.sap_document_id')
            ->where('document_approval_steps.approver_id', $request->user()->id)
            ->where('document_approval_steps.status', 'PENDING')
            ->where('document_approvals.status', 'PENDING')
            ->whereColumn('document_approval_steps.step_order', 'document_approvals.current_step')
            ->where('sap_documents.company_db', $companies->current())
            ->where('sap_documents.document_type', 'GOODS_ISSUE')
            ->with(['approval.document.pool', 'approval.document.department', 'approval.submitter'])
            ->orderBy('document_approvals.submitted_at')
            ->paginate(20);

        return view('operations.goods-issue-approvals.index', compact('steps'));
    }

    public function decide(
        Request $request,
        DocumentApprovalStep $step,
        SapCompanyContext $companies,
        DecideDocumentApproval $decide,
    ): RedirectResponse {
        $step->loadMissing(['approver', 'approval.document']);
        $document = $step->approval->document;

        abort_unless($document->company_db === $companies->current(), 404);
        abort_unless($document->document_type === 'GOODS_ISSUE', 404);
        abort_unless($step->approver_id === $request->user()->id, 403);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->input('decision') === 'REJECT')],
        ]);

        try {
            $decide->handle($step, $validated['decision'], $validated['note'] ?? null, $request->ip());

            return to_route('documents.show', $document)->with('status', $validated['decision'] === 'APPROVE'
                ? 'Goods Issue disetujui. Stok telah divalidasi ulang dan transaksi dikirim ke SAP.'
                : 'Goods Issue ditolak dan tidak dikirim ke SAP.');
        } catch (SapSubmissionException $exception) {
            return back()->withErrors(['approval' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return to_route('documents.show', $document)->with('warning', 'Approval tercatat, tetapi pengiriman Goods Issue ke SAP belum dapat dipastikan. Periksa Integration Monitor sebelum mencoba ulang.');
        }
    }
}
