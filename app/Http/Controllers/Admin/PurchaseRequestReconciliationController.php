<?php

namespace App\Http\Controllers\Admin;

use App\Application\Purchasing\ReconcilePurchaseRequest;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** TD-14: lets an administrator read SAP and reconcile one Purchase Request. It never posts to SAP. */
class PurchaseRequestReconciliationController extends Controller
{
    public function __invoke(
        Request $request,
        int $document,
        SapCompanyContext $companies,
        ReconcilePurchaseRequest $reconcile,
    ): RedirectResponse {
        $purchaseRequest = SapDocument::query()
            ->forCompany($companies->current())
            ->where('document_type', 'PURCHASE_REQUEST')
            ->findOrFail($document);
        $command = SapCommand::query()
            ->where('company_db', $purchaseRequest->company_db)
            ->where('transaction_uuid', $purchaseRequest->transaction_uuid)
            ->first();

        if ($command === null) {
            return to_route('documents.show', $purchaseRequest)->withErrors(['sap' => 'Command SAP untuk Purchase Request ini tidak ditemukan. Perlu investigasi manual.']);
        }

        $previousStatus = $command->status->value;
        $outcome = $reconcile->handle($command, $request->user(), $request->ip());
        $command->refresh();
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'event' => 'purchase_request.reconciliation_requested',
            'auditable_type' => SapDocument::class,
            'auditable_id' => $purchaseRequest->id,
            'metadata' => [
                'company_db' => $purchaseRequest->company_db,
                'command_id' => $command->id,
                'previous_status' => $previousStatus,
                'outcome' => $outcome->value,
                'match_count' => $command->reconcile_match_count,
            ],
            'ip_address' => $request->ip(),
        ]);
        $message = $outcome->message().' '.$outcome->nextStep();

        return $outcome->isResolved()
            ? to_route('documents.show', $purchaseRequest)->with('status', $message)
            : to_route('documents.show', $purchaseRequest)->withErrors(['sap' => $message]);
    }
}
