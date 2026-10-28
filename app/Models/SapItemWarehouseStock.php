<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_db',
    'sap_item_cache_id',
    'warehouse_code',
    'in_stock',
    'committed_stock',
    'ordered_stock',
    'available_stock',
    'minimum_stock',
    'is_locked',
    'source_payload',
    'synced_at',
])]
class SapItemWarehouseStock extends Model
{
    protected function casts(): array
    {
        return [
            'in_stock' => 'decimal:6',
            'committed_stock' => 'decimal:6',
            'ordered_stock' => 'decimal:6',
            'available_stock' => 'decimal:6',
            'minimum_stock' => 'decimal:6',
            'is_locked' => 'boolean',
            'source_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SapItemCache::class, 'sap_item_cache_id');
    }

    public function scopeForCompany(Builder $query, string $companyDb): Builder
    {
        return $query->where('company_db', $companyDb);
    }

    public function scopeForWarehouse(Builder $query, string $warehouseCode): Builder
    {
        return $query->where('warehouse_code', $warehouseCode);
    }

    public function reorderPoint(): float
    {
        $minimumStock = (float) $this->minimum_stock;

        return $minimumStock > 0
            ? $minimumStock
            : (float) config('sap.stock.low_threshold', 5);
    }

    public function status(): string
    {
        if ((float) $this->available_stock <= 0) {
            return 'OUT';
        }

        return (float) $this->available_stock <= $this->reorderPoint() ? 'LOW' : 'HEALTHY';
    }
}
