<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReadyToReceiveController extends Controller
{
    public function __invoke(Request $request, SapCompanyContext $companies): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['OPEN', 'RECEIVED'])],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        return view('operations.ready-to-receive.index', [
            'purchaseOrders' => SapDocument::query()
                ->forCompany($companies->current())
                ->visibleTo($request->user())
                ->where('document_type', 'PURCHASE_ORDER')
                ->where(function ($query): void {
                    $query->where('sap_business_status', BusinessStatus::Open->value)
                        ->orWhereHas('childRelations', fn ($relation) => $relation->where('relation_type', 'GOODS_RECEIPT_PO'));
                })
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('sap_doc_num', 'like', "%{$search}%")
                        ->orWhere('fpk_number', 'like', "%{$search}%")
                        ->orWhere('normalized_data', 'like', "%{$search}%")
                        ->orWhereHas('childRelations.childDocument', fn ($goodsReceipt) => $goodsReceipt->where('sap_doc_num', 'like', "%{$search}%"));
                }))
                ->when(($filters['status'] ?? null) === 'OPEN', fn ($query) => $query->whereDoesntHave('childRelations', fn ($relation) => $relation->where('relation_type', 'GOODS_RECEIPT_PO')))
                ->when(($filters['status'] ?? null) === 'RECEIVED', fn ($query) => $query->whereHas('childRelations', fn ($relation) => $relation->where('relation_type', 'GOODS_RECEIPT_PO')))
                ->with([
                    'parentRelations.parentDocument.parentRelations.parentDocument',
                    'childRelations.childDocument',
                ])
                ->latest('updated_at')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }
}
