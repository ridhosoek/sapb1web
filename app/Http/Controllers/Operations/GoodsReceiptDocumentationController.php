<?php

namespace App\Http\Controllers\Operations;

use App\Application\Items\SyncSelectedSapItems;
use App\Application\Receiving\PostGoodsReceipt;
use App\Application\Receiving\StoreGoodsReceiptAttachments;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGoodsReceiptAttachmentRequest;
use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\MasterData\SerialNumberConfigurationGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\SapItemCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class GoodsReceiptDocumentationController extends Controller
{
    public function show(
        int $purchaseOrder,
        SapCompanyContext $companies,
        SyncSelectedSapItems $syncSelectedItems,
        SerialNumberConfigurationGateway $serialNumberConfiguration,
    ): View {
        $document = $this->purchaseOrder($purchaseOrder, $companies);
        Gate::authorize('view', $document);
        $normalizedData = $document->normalized_data ?? [];
        $lines = collect($normalizedData['lines'] ?? [])
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values();
        $itemCodes = $lines
            ->map(fn (array $line): string => trim((string) ($line['item_code'] ?? $line['ItemCode'] ?? '')))
            ->filter()
            ->unique();
        $partialReceipts = (bool) config('sap.goods_receipt.partial_receipts_enabled');
        $goodsReceipts = $document->childRelations
            ->where('relation_type', 'GOODS_RECEIPT_PO')
            ->map(fn ($relation): ?SapDocument => $relation->childDocument)
            ->filter()
            ->sortBy('sap_doc_entry')
            ->values();
        $goodsReceipt = $partialReceipts
            ? null
            : $document->childRelations->firstWhere('relation_type', 'GOODS_RECEIPT_PO')?->childDocument;
        $attachmentDocumentIds = $partialReceipts
            ? collect([$document->id, ...$goodsReceipts->pluck('id')->all()])->values()
            : collect([$document->id, $goodsReceipt?->id])->filter()->values();
        $uncertainReceipts = $partialReceipts
            ? SapCommand::query()->uncertainGoodsReceipts($document->company_db, (int) $document->sap_doc_entry)->get()
            : collect();
        $hasOpenQuantity = $lines->contains(fn (array $line): bool => (float) ($line['open_quantity'] ?? $line['RemainingOpenQuantity'] ?? 0) > 0.000001);
        $receiptLocked = $partialReceipts && (
            $uncertainReceipts->isNotEmpty()
            || $document->sap_business_status !== BusinessStatus::Open
            || ! $hasOpenQuantity
        );
        $items = SapItemCache::query()
            ->forCompany($companies->current())
            ->whereIn('item_code', $itemCodes)
            ->get()
            ->keyBy('item_code');
        $itemMetadataWarning = null;
        $metadataRefreshCodes = $itemCodes->values();

        if (! $goodsReceipt && $metadataRefreshCodes->isNotEmpty()) {
            try {
                $syncSelectedItems->handle($metadataRefreshCodes->all());
                $items = SapItemCache::query()
                    ->forCompany($companies->current())
                    ->whereIn('item_code', $itemCodes)
                    ->get()
                    ->keyBy('item_code');
            } catch (Throwable $exception) {
                report($exception);
                $itemMetadataWarning = 'Metadata serial item belum dapat diperbarui dari SAP. Item yang statusnya belum diketahui tidak dapat diproses sampai koneksi SAP kembali normal.';
            }
        }
        $serialUniqueField = SerialNumberConfigurationGateway::None;
        $serialConfigurationWarning = null;

        if (! $goodsReceipt && $items->contains(fn (SapItemCache $item): bool => $item->requiresSerialNumbersOnReceipt())) {
            try {
                $serialUniqueField = $serialNumberConfiguration->uniqueField();
            } catch (Throwable $exception) {
                report($exception);
                $serialUniqueField = SerialNumberConfigurationGateway::None;
                $serialConfigurationWarning = 'Konfigurasi Unique Serial Numbers belum dapat dibaca. Isi minimal salah satu identitas pada setiap serial; validasi akhir tetap dilakukan oleh SAP saat GRPO dikirim.';
            }
        }

        return view('operations.ready-to-receive.show', [
            'purchaseOrder' => $document,
            'goodsReceipt' => $goodsReceipt,
            'goodsReceipts' => $goodsReceipts,
            'partialReceipts' => $partialReceipts,
            'receiptLocked' => $receiptLocked,
            'uncertainReceipts' => $uncertainReceipts,
            'receiptRequestUuid' => $partialReceipts && ! $receiptLocked ? (string) Str::uuid() : null,
            'normalizedData' => $normalizedData,
            'lines' => $lines,
            'items' => $items,
            'serialUniqueField' => $serialUniqueField,
            'serialUniqueFieldLabel' => SerialNumberConfigurationGateway::label($serialUniqueField),
            'serialConfigurationWarning' => $serialConfigurationWarning,
            'itemMetadataWarning' => $itemMetadataWarning,
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
                data: $request->safe()->only(['receipt_date', 'lines', 'receipt_request_uuid']),
                user: $request->user(),
                ipAddress: $request->ip(),
            );
        } catch (SapBusinessException $exception) {
            report($exception);

            $message = filled($exception->technicalMessage)
                ? 'SAP menolak GRPO: '.$exception->technicalMessage
                : $exception->getMessage();

            return back()
                ->withErrors(['sap' => $message])
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
