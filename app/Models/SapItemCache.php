<?php

namespace App\Models;

use App\Domain\Sap\Enums\SerialReceiptRequirement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_db',
    'item_code',
    'item_name',
    'inventory_uom',
    'item_group_code',
    'item_group_name',
    'is_inventory_item',
    'is_purchase_item',
    'manages_serial_numbers',
    'serial_management_method',
    'is_active',
    'source_payload',
    'synced_at',
])]
class SapItemCache extends Model
{
    protected $table = 'sap_items_cache';

    protected function casts(): array
    {
        return [
            'is_inventory_item' => 'boolean',
            'is_purchase_item' => 'boolean',
            'manages_serial_numbers' => 'boolean',
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

    /** Null bila nama grup item belum diketahui. */
    public function tireGroupStatus(): ?bool
    {
        $groupName = mb_strtoupper(trim((string) $this->item_group_name));

        return $groupName === '' ? null : $groupName === 'BAN';
    }

    public function serialReceiptRequirement(): SerialReceiptRequirement
    {
        $payload = $this->source_payload;

        if (! is_array($payload)) {
            return SerialReceiptRequirement::Undetermined;
        }

        return SerialReceiptRequirement::resolve(
            $payload['ManageSerialNumbers'] ?? null,
            $payload['SRIAndBatchManageMethod'] ?? null,
            $this->tireGroupStatus(),
        );
    }

    public function requiresSerialNumbersOnReceipt(): bool
    {
        return $this->serialReceiptRequirement() === SerialReceiptRequirement::SerialRequired;
    }

    public function manageSerialNumbersLabel(): string
    {
        return match (is_array($this->source_payload) ? ($this->source_payload['ManageSerialNumbers'] ?? null) : null) {
            'tYES' => 'Ya',
            'tNO' => 'Tidak',
            default => 'Belum dapat dipastikan',
        };
    }

    public function metadataStatusLabel(): string
    {
        if (! $this->hasCompleteMasterMetadata()) {
            return 'Belum tersedia';
        }

        return $this->serialReceiptRequirement() === SerialReceiptRequirement::Undetermined
            ? 'Terbaca, serial belum dapat dipastikan'
            : 'Terbaca dari SAP';
    }

    public function hasCompleteMasterMetadata(): bool
    {
        $payload = $this->source_payload;

        return is_array($payload)
            && array_key_exists('ItemsGroupCode', $payload)
            && array_key_exists('ManageSerialNumbers', $payload)
            && array_key_exists('SRIAndBatchManageMethod', $payload);
    }
}
