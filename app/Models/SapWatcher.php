<?php

namespace App\Models;

use App\Domain\Sap\Enums\WatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sap_document_id', 'watch_status', 'last_fingerprint', 'last_polled_at', 'next_poll_at', 'consecutive_failures', 'last_error'])]
class SapWatcher extends Model
{
    protected function casts(): array
    {
        return [
            'watch_status' => WatchStatus::class,
            'last_polled_at' => 'datetime',
            'next_poll_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SapDocument::class, 'sap_document_id');
    }
}
