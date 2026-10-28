<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_db', 'item_code', 'item_name', 'inventory_uom', 'is_inventory_item', 'is_purchase_item', 'is_active', 'source_payload', 'synced_at'])]
class SapItemCache extends Model
{
    protected $table = 'sap_items_cache';

    protected function casts(): array
    {
        return [
            'is_inventory_item' => 'boolean',
            'is_purchase_item' => 'boolean',
            'is_active' => 'boolean',
            'source_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where(function (Builder $query) use ($escaped) {
            $query->where('item_code', 'like', "%{$escaped}%")
                ->orWhere('item_name', 'like', "%{$escaped}%");
        });
    }

    public function scopeForCompany(Builder $query, string $companyDb): Builder
    {
        return $query->where('company_db', $companyDb);
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(SapItemWarehouseStock::class, 'sap_item_cache_id');
    }
}
