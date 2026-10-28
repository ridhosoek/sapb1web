<?php

namespace App\Application\Inventory;

use App\Exceptions\SapSubmissionException;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use Illuminate\Support\Collection;

class GoodsIssueStockService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, SapItemCache>  $items
     */
    public function ensureAvailable(array $data, string $companyDb, string $warehouseCode, Collection $items): void
    {
        $stocks = SapItemWarehouseStock::query()
            ->forCompany($companyDb)
            ->forWarehouse($warehouseCode)
            ->where('is_locked', false)
            ->whereIn('sap_item_cache_id', $items->keys())
            ->get()
            ->keyBy('sap_item_cache_id');

        foreach ($data['lines'] as $line) {
            $itemId = (int) $line['item_id'];
            $item = $items->get($itemId);
            $stock = $stocks->get($itemId);
            $available = (float) ($stock?->available_stock ?? 0);

            if (! $item || ! $stock || (float) $line['quantity'] > $available) {
                $itemLabel = $item?->item_code ?? "ID {$itemId}";
                $formattedAvailable = number_format($available, 2, ',', '.');

                throw new SapSubmissionException("Stok tersedia item {$itemLabel} di warehouse {$warehouseCode} hanya {$formattedAvailable}. Muat ulang form sebelum mengirim ulang.");
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function decrement(array $data, string $companyDb, string $warehouseCode): void
    {
        foreach ($data['lines'] as $line) {
            $stock = SapItemWarehouseStock::query()
                ->forCompany($companyDb)
                ->forWarehouse($warehouseCode)
                ->where('sap_item_cache_id', (int) $line['item_id'])
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                continue;
            }

            $quantity = (float) $line['quantity'];
            $stock->update([
                'in_stock' => max(0, (float) $stock->in_stock - $quantity),
                'available_stock' => max(0, (float) $stock->available_stock - $quantity),
                'synced_at' => now(),
            ]);
        }
    }
}
