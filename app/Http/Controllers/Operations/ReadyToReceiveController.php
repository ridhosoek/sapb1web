<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReadyToReceiveController extends Controller
{
    public function __invoke(Request $request, SapCompanyContext $companies): View
    {
        return view('operations.ready-to-receive.index', [
            'purchaseOrders' => SapDocument::query()
                ->forCompany($companies->current())
                ->visibleTo($request->user())
                ->where('document_type', 'PURCHASE_ORDER')
                ->where(function ($query): void {
                    $query->where('sap_business_status', BusinessStatus::Open->value)
                        ->orWhereHas('childRelations', fn ($relation) => $relation->where('relation_type', 'GOODS_RECEIPT_PO'));
                })
                ->with([
                    'parentRelations.parentDocument.parentRelations.parentDocument',
                    'childRelations.childDocument',
                ])
                ->latest('updated_at')
                ->paginate(20),
        ]);
    }
}
