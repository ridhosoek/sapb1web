<?php

namespace App\Application\Receiving;

use App\Models\AuditLog;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoreGoodsReceiptAttachments
{
    /**
     * @param  list<UploadedFile>  $images
     * @return Collection<int, SapDocumentAttachment>
     */
    public function handle(
        SapDocument $purchaseOrder,
        array $images,
        User $user,
        ?string $caption = null,
        ?string $ipAddress = null,
    ): Collection {
        if (! in_array($purchaseOrder->document_type, ['PURCHASE_ORDER', 'GOODS_RECEIPT_PO'], true)) {
            throw new RuntimeException('Dokumentasi Goods Receipt PO hanya dapat ditambahkan pada Purchase Order atau Goods Receipt PO.');
        }

        $disk = (string) config('sap.documents.goods_receipt_attachments.disk', 'local');
        $storedFiles = [];

        try {
            foreach ($images as $image) {
                if (! $image instanceof UploadedFile) {
                    throw new RuntimeException('Lampiran penerimaan tidak valid.');
                }

                $path = $image->store('goods-receipt-po/'.$purchaseOrder->id, $disk);

                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('Foto penerimaan gagal disimpan.');
                }

                $storedFiles[] = [
                    'path' => $path,
                    'original_name' => $image->getClientOriginalName(),
                    'mime_type' => $image->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $image->getSize(),
                ];
            }

            return DB::transaction(function () use ($purchaseOrder, $storedFiles, $disk, $user, $caption, $ipAddress): Collection {
                $attachments = collect($storedFiles)->map(fn (array $file): SapDocumentAttachment => SapDocumentAttachment::query()->create([
                    'sap_document_id' => $purchaseOrder->id,
                    'uploaded_by' => $user->id,
                    'disk' => $disk,
                    'path' => $file['path'],
                    'original_name' => $file['original_name'],
                    'mime_type' => $file['mime_type'],
                    'size_bytes' => $file['size_bytes'],
                    'caption' => filled($caption) ? $caption : null,
                ]));

                AuditLog::query()->create([
                    'actor_id' => $user->id,
                    'event' => 'goods_receipt_po.documentation_uploaded',
                    'auditable_type' => SapDocument::class,
                    'auditable_id' => $purchaseOrder->id,
                    'metadata' => [
                        'company_db' => $purchaseOrder->company_db,
                        'attachment_ids' => $attachments->pluck('id')->all(),
                        'file_count' => $attachments->count(),
                    ],
                    'ip_address' => $ipAddress,
                ]);

                return $attachments;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete(array_column($storedFiles, 'path'));

            throw $exception;
        }
    }
}
