<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pool_id', 'external_id', 'code', 'name', 'is_active', 'synced_at'])]
class ControlDepartment extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ControlPool::class, 'pool_id');
    }
}
