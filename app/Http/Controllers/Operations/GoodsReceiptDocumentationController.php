<?php

namespace App\Http\Controllers\Operations;

use App\Application\Receiving\PostGoodsReceipt;
use App\Application\Receiving\StoreGoodsReceiptAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGoodsReceiptAttachmentRequest;
use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\SapItemCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
        $rootPurchaseRequest = $document->parentRelations->first()?->parentDocument?->parentRelations->first()?->parentDocument;
        $goodsReceipt = $document->childRelations
            ->firstWhere('relation_type', 'GOODS_RECEIPT_PO')
            ?->childDocument;
        $attachmentDocumentIds = collect([$document->id, $goodsReceipt?->id])->filter()->values();

        return view('operations.ready-to-receive.show', [
            'purchaseOrder' => $document,
            'goodsReceipt' => $goodsReceipt,
            'normalizedData' => $normalizedData,
            'vehicle' => data_get(
                $normalizedData,
                'vehicle',
                data_get($normalizedData, 'vehicle_cost_center.center_code', data_get($rootPurchaseRequest?->normalized_data, 'vehicle_cost_center.center_code')),
            ),
            'lines' => $lines,
            'items' => SapItemCache::query()
                ->forCompany($companies->current())
                ->whereIn('id', $itemIds)
                ->get()
                ->keyBy('id'),
            'attachments' => SapDocumentAttachment::query()
                ->whereIn('sap_document_id', $attachmentDocumentIds)
                ->with('uploader')
                ->latest('created_at')
                ->latest('id')
                ->get(),
        ]);
    }

    public function receive(
        StoreGoodsReceiptRequest $request,
        int $purchaseOrder,
        SapCompanyContext $companies,
        PostGoodsReceipt $postGoodsReceipt,
        StoreGoodsReceiptAttachments $storeAttachments,
    ): RedirectResponse {
        $document = $this->purchaseOrder($purchaseOrder, $companies);
        Gate::authorize('view', $document);

        try {
            $goodsReceipt = $postGoodsReceipt->handle(
                purchaseOrder: $document,
                data: $request->safe()->only(['receipt_date', 'lines']),
                user: $request->user(),
                ipAddress: $request->ip(),
            );
        } catch (SapBusinessException $exception) {
            report($exception);

            return back()
                ->withErrors(['sap' => $exception->getMessage()])
                ->withInput();
        } catch (ConnectionException $exception) {
            report($exception);

            return back()
                ->withErrors(['sap' => 'Koneksi ke SAP terputus saat pengiriman. Status transaksi belum dapat dipastikan; jangan kirim ulang sebelum diperiksa di Integration Monitor.'])
                ->withInput();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(['sap' => 'GRPO belum dapat dipastikan tersimpan. Jangan kirim ulang sebelum statusnya diperiksa di Integration Monitor.'])
                ->withInput();
        }

        $uploadedPhotoCount = 0;
        $photoWarning = null;

        if ($request->hasFile('images')) {
            try {
                $attachments = $storeAttachments->handle(
                    purchaseOrder: $goodsReceipt,
                    images: $request->file('images', []),
                    user: $request->user(),
                    caption: $request->validated('caption'),
                    ipAddress: $request->ip(),
                );
                $uploadedPhotoCount = $attachments->count();
            } catch (Throwable $exception) {
                report($exception);
                $photoWarning = 'GRPO berhasil dibuat di SAP, tetapi foto belum dapat disimpan. Anda dapat mengunggah foto kembali tanpa membuat GRPO baru.';
            }
        }

        $message = 'GRPO SAP '.($goodsReceipt->sap_doc_num ?? '#'.$goodsReceipt->sap_doc_entry).' berhasil dibuat.';

        if ($uploadedPhotoCount > 0) {
            $message .= " {$uploadedPhotoCount} foto disimpan privat di portal.";
        }

        $redirect = to_route('ready-to-receive.show', $document)->with('status', $message);

        return $photoWarning ? $redirect->with('warning', $photoWarning) : $redirect;
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
            ->with([
                'pool',
                'department',
                'parentRelations.parentDocument.parentRelations.parentDocument',
                'childRelations.childDocument',
            ])
            ->whereKey($documentId)
            ->firstOrFail();
    }
}
