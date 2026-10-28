<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pool_id', 'external_id', 'license_plate', 'description', 'last_known_km', 'is_active', 'synced_at'])]
class ControlVehicle extends Model
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
