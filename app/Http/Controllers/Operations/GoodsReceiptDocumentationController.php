<?php

namespace App\Http\Controllers\Operations;

use App\Application\Receiving\StoreGoodsReceiptAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGoodsReceiptAttachmentRequest;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class GoodsReceiptDocumentationController extends Controller
{
    public function show(int $purchaseOrder, SapCompanyContext $companies): View
    {
        $document = $this->purchaseOrder($purchaseOrder, $companies);
        Gate::authorize('view', $document);
        $normalizedData = $document->normalized_data ?? [];
        $lines = collect($normalizedData['lines'] ?? [])
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values();
        $itemIds = $lines->pluck('item_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique();

        return view('operations.ready-to-receive.show', [
            'purchaseOrder' => $document,
            'normalizedData' => $normalizedData,
            'lines' => $lines,
            'items' => SapItemCache::query()
                ->forCompany($companies->current())
                ->whereIn('id', $itemIds)
                ->get()
                ->keyBy('id'),
            'attachments' => $document->attachments()
                ->with('uploader')
                ->latest('created_at')
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(
        StoreGoodsReceiptAttachmentRequest $request,
        int $purchaseOrder,
        SapCompanyContext $companies,
        StoreGoodsReceiptAttachments $storeAttachments,
    ): RedirectResponse {
        $document = $this->purchaseOrder($purchaseOrder, $companies);
        Gate::authorize('view', $document);

        try {
            $attachments = $storeAttachments->handle(
                purchaseOrder: $document,
                images: $request->file('images', []),
                user: $request->user(),
                caption: $request->validated('caption'),
                ipAddress: $request->ip(),
            );

            return to_route('ready-to-receive.show', $document)
                ->with('status', $attachments->count().' foto penerimaan berhasil disimpan di portal.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(['images' => 'Foto penerimaan belum dapat disimpan. Silakan coba kembali.'])
                ->withInput();
        }
    }

    private function purchaseOrder(int $documentId, SapCompanyContext $companies): SapDocument
    {
        return SapDocument::query()
            ->forCompany($companies->current())
            ->where('document_type', 'PURCHASE_ORDER')
            ->with(['pool', 'department'])
            ->whereKey($documentId)
            ->firstOrFail();
    }
}
