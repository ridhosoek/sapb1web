<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['parent_document_id', 'child_document_id', 'relation_type', 'discovered_by', 'relation_metadata'])]
class SapDocumentRelation extends Model
{
    protected function casts(): array
    {
        return [
            'relation_metadata' => 'array',
        ];
    }

    public function parentDocument(): BelongsTo
    {
        return $this->belongsTo(SapDocument::class, 'parent_document_id');
    }

    public function childDocument(): BelongsTo
    {
        return $this->belongsTo(SapDocument::class, 'child_document_id');
    }
}
