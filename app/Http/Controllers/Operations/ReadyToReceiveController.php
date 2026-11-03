<?php

namespace App\Http\Controllers\Operations;

use App\Application\Receiving\SyncReadyPurchaseOrders;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ReadyToReceiveController extends Controller
{
    public function __invoke(
        Request $request,
        SapCompanyContext $companies,
        SyncReadyPurchaseOrders $syncReadyPurchaseOrders,
    ): View {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $series = (int) config('sap.ready_to_receive.series', 887);
        $fromDate = now()->subMonthsNoOverflow(max(1, (int) config('sap.ready_to_receive.lookback_months', 2)))->toDateString();
        $syncWarning = null;

        try {
            $syncReadyPurchaseOrders->handle($request->user());
        } catch (Throwable $exception) {
            report($exception);
            $syncWarning = 'Data terbaru dari SAP belum dapat ditarik. Daftar di bawah menggunakan cache portal terakhir; silakan refresh beberapa saat lagi.';
        }

        return view('operations.ready-to-receive.index', [
            'purchaseOrders' => SapDocument::query()
                ->forCompany($companies->current())
                ->visibleTo($request->user())
                ->where('document_type', 'PURCHASE_ORDER')
                ->where('sap_business_status', BusinessStatus::Open->value)
                ->where('normalized_data->series', $series)
                ->where('normalized_data->document_date', '>=', $fromDate)
                ->whereDoesntHave('childRelations', fn ($relation) => $relation->where('relation_type', 'GOODS_RECEIPT_PO'))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('sap_doc_num', 'like', "%{$search}%")
                        ->orWhere('fpk_number', 'like', "%{$search}%")
                        ->orWhere('normalized_data', 'like', "%{$search}%");
                }))
                ->with([
                    'parentRelations.parentDocument.parentRelations.parentDocument',
                ])
                ->latest('updated_at')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
            'fromDate' => $fromDate,
            'series' => $series,
            'syncWarning' => $syncWarning,
        ]);
    }
}
