<?php

namespace App\Application\Items;

use App\Integration\Sap\MasterData\ItemGateway;
use App\Integration\Sap\MasterData\WarehouseStockGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use App\Models\UserOperationalProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class SyncSapItems
{
    public function __construct(
        private readonly ItemGateway $items,
        private readonly WarehouseStockGateway $warehouseStocks,
        private readonly SapCompanyContext $companies,
    ) {}

    /**
     * @return array{processed: int, created: int, updated: int, deactivated: int, stock_processed: int, stock_removed: int, warehouses: int}
     */
    public function handle(int $limit = 0, int $pageSize = 20): array
    {
        $processed = 0;
        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $skip = 0;
        $synchronizedItemCodes = [];
        $companyDb = $this->companies->current();
        $stockProcessed = 0;
        $stockRemoved = 0;
        $warehouses = 0;

        do {
            $remaining = $limit > 0 ? $limit - $processed : $pageSize;
            $requested = min($pageSize, $remaining);

            if ($requested <= 0) {
                break;
            }

            $page = $this->items->activeItems($requested, $skip);

            DB::transaction(function () use ($page, &$created, &$updated, &$synchronizedItemCodes, $companyDb): void {
                foreach ($page as $item) {
                    $itemCode = $item['ItemCode'] ?? null;

                    if (! is_string($itemCode) || trim($itemCode) === '') {
                        throw new UnexpectedValueException('SAP Item is missing ItemCode.');
                    }

                    $itemName = is_string($item['ItemName'] ?? null) && trim($item['ItemName']) !== ''
                        ? trim($item['ItemName'])
                        : '(Tanpa nama)';

                    $cachedItem = SapItemCache::query()->updateOrCreate(
                        ['company_db' => $companyDb, 'item_code' => $itemCode],
                        [
                            'item_name' => $itemName,
                            'inventory_uom' => is_string($item['InventoryUOM'] ?? null) ? $item['InventoryUOM'] : null,
                            'is_inventory_item' => ($item['InventoryItem'] ?? null) === 'tYES',
                            'is_purchase_item' => ($item['PurchaseItem'] ?? null) === 'tYES',
                            'is_active' => ($item['Frozen'] ?? null) === 'tNO',
                            'source_payload' => $item,
                            'synced_at' => now(),
                        ],
                    );

                    $cachedItem->wasRecentlyCreated ? $created++ : $updated++;
                    $synchronizedItemCodes[$itemCode] = true;
                }
            });

            $received = count($page);
            $processed += $received;
            $skip += $received;
        } while ($received === $requested && ($limit === 0 || $processed < $limit));

        if ($limit === 0) {
            SapItemCache::query()
                ->forCompany($companyDb)
                ->where('is_active', true)
                ->select(['id', 'item_code'])
                ->chunkById(500, function (Collection $cachedItems) use (&$deactivated, $synchronizedItemCodes): void {
                    $staleItemIds = $cachedItems
                        ->reject(fn (SapItemCache $item): bool => isset($synchronizedItemCodes[$item->item_code]))
                        ->pluck('id');

                    if ($staleItemIds->isNotEmpty()) {
                        $deactivated += SapItemCache::query()
                            ->whereKey($staleItemIds)
                            ->update(['is_active' => false]);
                    }
                });

            foreach ($this->warehouseCodes() as $warehouseCode) {
                $stockResult = $this->synchronizeWarehouse($companyDb, $warehouseCode, $pageSize);
                $stockProcessed += $stockResult['processed'];
                $stockRemoved += $stockResult['removed'];
                $warehouses++;
            }
        }

        return compact('processed', 'created', 'updated', 'deactivated', 'warehouses') + [
            'stock_processed' => $stockProcessed,
            'stock_removed' => $stockRemoved,
        ];
    }

    /** @return list<string> */
    private function warehouseCodes(): array
    {
        return collect(config('sap.stock.warehouses', []))
            ->merge(UserOperationalProfile::query()->whereNotNull('warehouse_code')->pluck('warehouse_code'))
            ->filter(fn (mixed $warehouseCode): bool => is_string($warehouseCode) && trim($warehouseCode) !== '')
            ->map(fn (string $warehouseCode): string => trim($warehouseCode))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array{processed: int, removed: int} */
    private function synchronizeWarehouse(string $companyDb, string $warehouseCode, int $pageSize): array
    {
        $processed = 0;
        $removed = 0;
        $skip = 0;
        $synchronizedStockIds = [];

        do {
            $page = $this->warehouseStocks->activeInventoryForWarehouse($warehouseCode, $pageSize, $skip);
            $itemCodes = collect($page)
                ->pluck('item.ItemCode')
                ->filter(fn (mixed $itemCode): bool => is_string($itemCode) && trim($itemCode) !== '')
                ->all();
            $items = SapItemCache::query()
                ->forCompany($companyDb)
                ->whereIn('item_code', $itemCodes)
                ->get()
                ->keyBy('item_code');

            DB::transaction(function () use ($page, $items, $companyDb, $warehouseCode, &$synchronizedStockIds): void {
                foreach ($page as $row) {
                    $itemCode = trim((string) ($row['item']['ItemCode'] ?? ''));
                    $stock = $row['stock'];
                    $cachedItem = $items->get($itemCode);

                    if (! $cachedItem || ($stock['WarehouseCode'] ?? null) !== $warehouseCode) {
                        continue;
                    }

                    $inStock = $this->numericValue($stock['InStock'] ?? 0);
                    $committedStock = $this->numericValue($stock['Committed'] ?? 0);
                    $warehouseStock = SapItemWarehouseStock::query()->updateOrCreate(
                        [
                            'company_db' => $companyDb,
                            'sap_item_cache_id' => $cachedItem->id,
                            'warehouse_code' => $warehouseCode,
                        ],
                        [
                            'in_stock' => $inStock,
                            'committed_stock' => $committedStock,
                            'ordered_stock' => $this->numericValue($stock['Ordered'] ?? 0),
                            'available_stock' => $inStock - $committedStock,
                            'minimum_stock' => $this->numericValue($stock['MinimalStock'] ?? 0),
                            'is_locked' => in_array($stock['Locked'] ?? null, ['Y', 'tYES'], true),
                            'source_payload' => $stock,
                            'synced_at' => now(),
                        ],
                    );

                    $synchronizedStockIds[$warehouseStock->id] = true;
                }
            });

            $received = count($page);
            $processed += $received;
            $skip += $received;
        } while ($received === $pageSize);

        SapItemWarehouseStock::query()
            ->forCompany($companyDb)
            ->forWarehouse($warehouseCode)
            ->select('id')
            ->chunkById(500, function (Collection $stocks) use (&$removed, $synchronizedStockIds): void {
                $staleStockIds = $stocks
                    ->reject(fn (SapItemWarehouseStock $stock): bool => isset($synchronizedStockIds[$stock->id]))
                    ->pluck('id');

                if ($staleStockIds->isNotEmpty()) {
                    $removed += SapItemWarehouseStock::query()->whereKey($staleStockIds)->delete();
                }
            });

        return compact('processed', 'removed');
    }

    private function numericValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
