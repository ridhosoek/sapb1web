<?php

namespace App\Application\Inventory;

use App\Models\AuditLog;
use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoreGoodsIssueAttachments
{
    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, SapDocumentAttachment>
     */
    public function handle(
        SapDocument $document,
        array $files,
        User $user,
        ?string $caption = null,
        ?string $ipAddress = null,
    ): Collection {
        if ($document->document_type !== 'GOODS_ISSUE') {
            throw new RuntimeException('Scan FPK hanya dapat ditambahkan pada dokumen Goods Issue.');
        }

        $disk = (string) config('sap.documents.goods_issue_attachments.disk', 'local');
        $storedFiles = [];

        try {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    throw new RuntimeException('Lampiran scan FPK tidak valid.');
                }

                $path = $file->store('goods-issues/'.$document->id.'/fpk-scans', $disk);

                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('Scan FPK gagal disimpan.');
                }

                $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $originalName = preg_replace('/[[:cntrl:]]/u', '', $originalName) ?: 'lampiran-fpk';
                $storedFiles[] = [
                    'path' => $path,
                    'original_name' => Str::limit($originalName, 240, ''),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                ];
            }

            return DB::transaction(function () use ($document, $storedFiles, $disk, $user, $caption, $ipAddress): Collection {
                $attachments = collect($storedFiles)->map(fn (array $file): SapDocumentAttachment => SapDocumentAttachment::query()->create([
                    'sap_document_id' => $document->id,
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
                    'event' => 'goods_issue.fpk_scan_uploaded',
                    'auditable_type' => SapDocument::class,
                    'auditable_id' => $document->id,
                    'metadata' => [
                        'company_db' => $document->company_db,
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
