<?php

namespace App\Models;

use Database\Factories\SapDocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sap_document_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'caption'])]
class SapDocumentAttachment extends Model
{
    /** @use HasFactory<SapDocumentAttachmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SapDocument::class, 'sap_document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
