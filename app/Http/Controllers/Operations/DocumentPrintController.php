<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DocumentPrintController extends Controller
{
    public function __invoke(int $document, SapCompanyContext $companies): View
    {
        $sapDocument = SapDocument::query()
            ->forCompany($companies->current())
            ->with(['pool', 'department', 'approval.steps.approver', 'attachments.uploader'])
            ->whereKey($document)
            ->firstOrFail();

        Gate::authorize('view', $sapDocument);
        abort_unless(in_array($sapDocument->document_type, ['PURCHASE_REQUEST', 'GOODS_ISSUE', 'PURCHASE_ORDER'], true), 404);

        $normalizedData = $sapDocument->normalized_data ?? [];
        $lines = collect($normalizedData['lines'] ?? [])
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values();
        $itemIds = $lines->pluck('item_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique();
        $items = SapItemCache::query()
            ->forCompany($companies->current())
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        return view('operations.documents.print', [
            'document' => $sapDocument,
            'normalizedData' => $normalizedData,
            'lines' => $lines,
            'items' => $items,
            'documentTitle' => match ($sapDocument->document_type) {
                'PURCHASE_REQUEST' => 'Purchase Request',
                'GOODS_ISSUE' => 'Goods Issue',
                'PURCHASE_ORDER' => 'Form Goods Receipt PO',
            },
            'generatedAt' => now(),
        ]);
    }
}
