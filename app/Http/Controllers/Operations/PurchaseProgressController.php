<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Sap\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseProgressController extends Controller
{
    public function __invoke(Request $request, SapCompanyContext $companies): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(IntegrationStatus::class)],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        return view('operations.purchase-progress.index', [
            'documents' => SapDocument::query()
                ->with(['watcher', 'childRelations.childDocument.childRelations.childDocument.childRelations.childDocument'])
                ->forCompany($companies->current())
                ->visibleTo($request->user())
                ->where('document_type', 'PURCHASE_REQUEST')
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('sap_doc_num', 'like', "%{$search}%")
                        ->orWhere('fpk_number', 'like', "%{$search}%")
                        ->orWhere('normalized_data', 'like', "%{$search}%");
                }))
                ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('integration_status', $filters['status']))
                ->latest('updated_at')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
            'integrationStatuses' => IntegrationStatus::cases(),
        ]);
    }
}
