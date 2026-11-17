<?php

namespace App\Http\Controllers\Operations;

use App\Application\Items\SyncSelectedSapItems;
use App\Application\Receiving\PostGoodsReceipt;
use App\Application\Receiving\StoreGoodsReceiptAttachments;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\PurchaseOrderAuthorization;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class GoodsReceiptDocumentationController extends Controller
{
    public function show(
        Request $request,
        int $purchaseOrder,
        SapCompanyContext $companies,
        SyncSelectedSapItems $syncSelectedItems,
        SerialNumberConfigurationGateway $serialNumberConfiguration,
    ): View {
        $document = $this->purchaseOrder($purchaseOrder, $companies);
        Gate::authorize('view', $document);
        $user = $request->user();
        $normalizedData = $document->normalized_data ?? [];
        $lines = collect($normalizedData['lines'] ?? [])
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values();
        $itemCodes = $lines
            ->map(fn (array $line): string => trim((string) ($line['item_code'] ?? $line['ItemCode'] ?? '')))
            ->filter()
            ->unique();
        $partialReceipts = (bool) config('sap.goods_receipt.partial_receipts_enabled');
        $allGoodsReceipts = $document->childRelations
            ->where('relation_type', 'GOODS_RECEIPT_PO')
            ->map(fn ($relation): ?SapDocument => $relation->childDocument)
            ->filter()
            ->sortBy('sap_doc_entry')
            ->values();
        // BP-10: the page states that receipts exist, but only shows the ones this user may see (their own).
        $goodsReceipts = $allGoodsReceipts
            ->filter(fn (SapDocument $receipt): bool => $user->can('view', $receipt))
            ->values();
        $goodsReceipt = $partialReceipts
            ? null
            : $document->childRelations->firstWhere('relation_type', 'GOODS_RECEIPT_PO')?->childDocument;
        $visibleGoodsReceipt = $goodsReceipt !== null && $user->can('view', $goodsReceipt) ? $goodsReceipt : null;
        $attachmentDocumentIds = $partialReceipts
            ? collect([$document->id, ...$goodsReceipts->pluck('id')->all()])->values()
            : collect([$document->id, $visibleGoodsReceipt?->id])->filter()->values();
        $uncertainReceipts = $partialReceipts
            ? SapCommand::query()->uncertainGoodsReceipts($document->company_db, (int) $document->sap_doc_entry)->get()
            : collect();
        $hasOpenQuantity = $lines->contains(fn (array $line): bool => (float) ($line['open_quantity'] ?? $line['RemainingOpenQuantity'] ?? 0) > 0.000001);
        // Cached status only decides what the screen offers; the live status is read again before every POST.
        $authorizationHold = $partialReceipts || ! $goodsReceipt
            ? PurchaseOrderAuthorization::holdReason($normalizedData['authorization_status'] ?? null)
            : null;
        $receiptLocked = $authorizationHold !== null || ($partialReceipts && (
            $uncertainReceipts->isNotEmpty()
            || $document->sap_business_status !== BusinessStatus::Open
            || ! $hasOpenQuantity
        ));
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
            'visibleGoodsReceipt' => $visibleGoodsReceipt,
            'goodsReceipts' => $goodsReceipts,
            'goodsReceiptCount' => $allGoodsReceipts->count(),
            'hiddenGoodsReceiptCount' => $allGoodsReceipts->count() - $goodsReceipts->count(),
            'partialReceipts' => $partialReceipts,
            'receiptLocked' => $receiptLocked,
            'authorizationHold' => $authorizationHold,
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
                ->with(['uploader', 'document'])
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
    ): RedirectResponse|JsonResponse {
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

            $message = match (true) {
                $exception->isBaseDocumentAwaitingApproval() => 'SAP menolak GRPO (-5002): PO ini masih dianggap menunggu approval oleh SAP, walaupun statusnya sudah memenuhi aturan Generated/Approved di portal. '
                    .'GRPO ini tidak dikirim ulang otomatis. Minta tim SAP memeriksa draft approval PO tersebut (pada kasus yang diperiksa, draft PO masih terbuka) sebelum mencoba lagi. Pesan SAP: '.$exception->technicalMessage,
                $exception->isPostingPeriodRejection() => 'SAP menolak GRPO: '.$exception->getMessage()
                    .' Tanggal penerimaan tidak diubah dan GRPO tidak dikirim ulang otomatis. Pilih tanggal lain atau minta tim SAP membuka periodenya. Pesan SAP: '.$exception->technicalMessage,
                filled($exception->technicalMessage) => 'SAP menolak GRPO: '.$exception->technicalMessage,
                default => $exception->getMessage(),
            };

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()
                ->withErrors(['sap' => $message])
                ->withInput();
        } catch (ConnectionException $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Koneksi ke SAP terputus saat pengiriman. Status transaksi belum dapat dipastikan; jangan kirim ulang sebelum diperiksa di Integration Monitor.'], 503);
            }

            return back()
                ->withErrors(['sap' => 'Koneksi ke SAP terputus saat pengiriman. Status transaksi belum dapat dipastikan; jangan kirim ulang sebelum diperiksa di Integration Monitor.'])
                ->withInput();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'GRPO belum dapat dipastikan tersimpan. Jangan kirim ulang sebelum statusnya diperiksa di Integration Monitor.'], 503);
            }

            return back()
                ->withErrors(['sap' => 'GRPO belum dapat dipastikan tersimpan. Jangan kirim ulang sebelum statusnya diperiksa di Integration Monitor.'])
                ->withInput();
        }

        $message = 'GRPO SAP '.($goodsReceipt->sap_doc_num ?? '#'.$goodsReceipt->sap_doc_entry).' berhasil dibuat.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'goods_receipt_id' => $goodsReceipt->id,
                'redirect_url' => route('ready-to-receive.show', $document),
            ]);
        }

        return to_route('ready-to-receive.show', $document)->with('status', $message);
    }

    /**
     * Photos are attached to one Goods Receipt PO of this purchase order and never call SAP, so a failed or repeated
     * upload cannot create a second GRPO.
     */
    public function store(
        StoreGoodsReceiptAttachmentRequest $request,
        int $purchaseOrder,
        SapCompanyContext $companies,
        StoreGoodsReceiptAttachments $storeAttachments,
    ): RedirectResponse|JsonResponse {
        $document = $this->purchaseOrder($purchaseOrder, $companies);
        Gate::authorize('view', $document);

        $goodsReceipts = $this->goodsReceiptsOf($document)
            ->filter(fn (SapDocument $receipt): bool => $request->user()->can('view', $receipt));

        if ($goodsReceipts->isEmpty()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Foto hanya dapat diunggah setelah GRPO untuk PO ini berhasil dibuat.'], 422);
            }

            return back()
                ->withErrors(['goods_receipt_id' => 'Foto hanya dapat diunggah setelah GRPO untuk PO ini berhasil dibuat.'])
                ->withInput();
        }

        $goodsReceipt = $goodsReceipts->firstWhere('id', (int) $request->validated('goods_receipt_id'));

        if (! $goodsReceipt) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'GRPO tujuan foto bukan bagian dari PO ini. Muat ulang halaman lalu pilih GRPO yang tersedia.'], 422);
            }

            return back()
                ->withErrors(['goods_receipt_id' => 'GRPO tujuan foto bukan bagian dari PO ini. Muat ulang halaman lalu pilih GRPO yang tersedia.'])
                ->withInput();
        }

        try {
            $attachments = $storeAttachments->handle(
                goodsReceipt: $goodsReceipt,
                images: $request->file('images', []),
                user: $request->user(),
                caption: $request->validated('caption'),
                ipAddress: $request->ip(),
            );

            $message = $attachments->count().' foto disimpan privat di portal untuk GRPO '.($goodsReceipt->sap_doc_num ?? '#'.$goodsReceipt->sap_doc_entry).'.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'redirect_url' => route('ready-to-receive.show', $document)]);
            }

            return to_route('ready-to-receive.show', $document)->with('status', $message);
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'GRPO berhasil dibuat, tetapi foto belum dapat dipastikan tersimpan. Periksa galeri GRPO sebelum mengunggah ulang.'], 503);
            }

            return back()
                ->withErrors(['images' => 'Foto penerimaan belum dapat disimpan. GRPO tidak dikirim ulang ke SAP; pilih foto lalu coba unggah kembali.'])
                ->withInput();
        }
    }

    /**
     * Goods Receipt POs of this purchase order within the same SAP company, oldest first.
     *
     * @return Collection<int, SapDocument>
     */
    private function goodsReceiptsOf(SapDocument $purchaseOrder): Collection
    {
        return $purchaseOrder->childRelations
            ->where('relation_type', 'GOODS_RECEIPT_PO')
            ->map(fn ($relation): ?SapDocument => $relation->childDocument)
            ->filter(fn (?SapDocument $goodsReceipt): bool => $goodsReceipt !== null && $goodsReceipt->company_db === $purchaseOrder->company_db)
            ->sortBy('sap_doc_entry')
            ->values();
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
