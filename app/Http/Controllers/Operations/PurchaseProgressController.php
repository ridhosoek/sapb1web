<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseProgressController extends Controller
{
    public function __invoke(Request $request, SapCompanyContext $companies): View
    {
        return view('operations.purchase-progress.index', [
            'documents' => SapDocument::query()
                ->with(['watcher', 'childRelations.childDocument.childRelations.childDocument.childRelations.childDocument'])
                ->forCompany($companies->current())
                ->visibleTo($request->user())
                ->where('document_type', 'PURCHASE_REQUEST')
                ->latest('updated_at')
                ->paginate(20),
        ]);
    }
}
