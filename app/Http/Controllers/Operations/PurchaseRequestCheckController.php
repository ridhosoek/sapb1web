<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PurchaseRequestCheckController extends Controller
{
    public function __invoke(Request $request, int $document, SapCompanyContext $companies): RedirectResponse
    {
        $purchaseRequest = SapDocument::query()
            ->forCompany($companies->current())
            ->whereKey($document)
            ->firstOrFail();

        Gate::authorize('checkPurchaseRequest', $purchaseRequest);

        DB::transaction(function () use ($request, $purchaseRequest): void {
            $lockedPurchaseRequest = SapDocument::query()->lockForUpdate()->findOrFail($purchaseRequest->id);
            Gate::authorize('checkPurchaseRequest', $lockedPurchaseRequest);
            $checkedAt = now();

            $lockedPurchaseRequest->forceFill([
                'admin_ho_checked_by' => $request->user()->id,
                'admin_ho_checked_at' => $checkedAt,
                'admin_ho_check_ip' => $request->ip(),
            ])->save();

            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'event' => 'purchase_request.checked_by_admin_ho',
                'auditable_type' => SapDocument::class,
                'auditable_id' => $lockedPurchaseRequest->id,
                'metadata' => [
                    'company_db' => $lockedPurchaseRequest->company_db,
                    'checked_at' => $checkedAt->toIso8601String(),
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return to_route('documents.show', $purchaseRequest)
            ->with('status', 'Purchase Request sudah diperiksa oleh Admin HO. Nama dan waktu check akan tampil pada cetakan.');
    }
}
