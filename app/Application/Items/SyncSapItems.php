<?php

namespace App\Application\Items;

use App\Integration\Sap\MasterData\ItemGateway;
use App\Integration\Sap\MasterData\ItemGroupGateway;
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
        private readonly ItemGroupGateway $itemGroups,
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
        $itemGroupNames = $this->itemGroups->namesByNumber();

        do {
            $remaining = $limit > 0 ? $limit - $processed : $pageSize;
            $requested = min($pageSize, $remaining);

            if ($requested <= 0) {
                break;
            }

            $page = $this->items->activeItems($requested, $skip);

            DB::transaction(function () use ($page, &$created, &$updated, &$synchronizedItemCodes, $companyDb, $itemGroupNames): void {
                foreach ($page as $item) {
                    $itemCode = $item['ItemCode'] ?? null;

                    if (! is_string($itemCode) || trim($itemCode) === '') {
                        throw new UnexpectedValueException('SAP Item is missing ItemCode.');
                    }

                    $itemName = is_string($item['ItemName'] ?? null) && trim($item['ItemName']) !== ''
                        ? trim($item['ItemName'])
                        : '(Tanpa nama)';
                    $itemGroupCode = is_numeric($item['ItemsGroupCode'] ?? null)
                        ? (int) $item['ItemsGroupCode']
                        : null;

                    $cachedItem = SapItemCache::query()->updateOrCreate(
                        ['company_db' => $companyDb, 'item_code' => $itemCode],
                        [
                            'item_name' => $itemName,
                            'inventory_uom' => is_string($item['InventoryUOM'] ?? null) ? $item['InventoryUOM'] : null,
                            'item_group_code' => $itemGroupCode,
                            'item_group_name' => $itemGroupCode !== null ? ($itemGroupNames[$itemGroupCode] ?? null) : null,
                            'is_inventory_item' => ($item['InventoryItem'] ?? null) === 'tYES',
                            'is_purchase_item' => ($item['PurchaseItem'] ?? null) === 'tYES',
                            'manages_serial_numbers' => ($item['ManageSerialNumbers'] ?? null) === 'tYES',
                            'serial_management_method' => is_string($item['SRIAndBatchManageMethod'] ?? null)
                                ? $item['SRIAndBatchManageMethod']
                                : null,
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
        } while ($received > 0 && ($limit === 0 || $processed < $limit));

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

            $stockResult = $this->synchronizeWarehouses(pageSize: $pageSize);
            $stockProcessed = $stockResult['processed'];
            $stockRemoved = $stockResult['removed'];
            $warehouses = $stockResult['warehouses'];
        }

        return compact('processed', 'created', 'updated', 'deactivated', 'warehouses') + [
            'stock_processed' => $stockProcessed,
            'stock_removed' => $stockRemoved,
        ];
    }

    /**
     * @param  list<string>  $warehouseCodes
     * @return array{processed: int, removed: int, warehouses: int}
     */
    public function synchronizeWarehouses(array $warehouseCodes = [], int $pageSize = 100): array
    {
        $companyDb = $this->companies->current();
        $codes = collect($warehouseCodes === [] ? $this->warehouseCodes() : $warehouseCodes)
            ->filter(fn (mixed $warehouseCode): bool => is_string($warehouseCode) && trim($warehouseCode) !== '')
            ->map(fn (string $warehouseCode): string => trim($warehouseCode))
            ->unique()
            ->sort()
            ->values();
        $processed = 0;
        $removed = 0;

        foreach ($codes as $warehouseCode) {
            $result = $this->synchronizeWarehouse($companyDb, $warehouseCode, $pageSize);
            $processed += $result['processed'];
            $removed += $result['removed'];
        }

        return [
            'processed' => $processed,
            'removed' => $removed,
            'warehouses' => $codes->count(),
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

            DB::transaction(function () use ($page, $items, $companyDb, $warehouseCode, &$processed, &$synchronizedStockIds): void {
                foreach ($page as $row) {
                    $itemCode = trim((string) ($row['item']['ItemCode'] ?? ''));
                    $stock = $row['stock'];
                    if ($itemCode === '' || ($stock['WarehouseCode'] ?? null) !== $warehouseCode) {
                        continue;
                    }

                    $inStock = $this->numericValue($stock['InStock'] ?? 0);
                    $committedStock = $this->numericValue($stock['Committed'] ?? 0);
                    $availableStock = $inStock - $committedStock;

                    if ($availableStock <= 0) {
                        continue;
                    }

                    $item = $row['item'];
                    $cachedItem = $items->get($itemCode);

                    if (! $cachedItem) {
                        $cachedItem = SapItemCache::query()->firstOrNew([
                            'company_db' => $companyDb,
                            'item_code' => $itemCode,
                        ]);

                        if (! $cachedItem->exists) {
                            $cachedItem->is_purchase_item = false;
                        }
                    }

                    $itemName = trim((string) ($item['ItemName'] ?? ''));
                    $inventoryUom = trim((string) ($item['InventoryUOM'] ?? ''));
                    $cachedItem->fill([
                        'item_name' => $itemName !== '' ? $itemName : '(Tanpa nama)',
                        'inventory_uom' => $inventoryUom !== '' ? $inventoryUom : null,
                        'is_inventory_item' => true,
                        'is_active' => true,
                        'synced_at' => now(),
                    ])->save();
                    $items->put($itemCode, $cachedItem);

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
                            'available_stock' => $availableStock,
                            'minimum_stock' => $this->numericValue($stock['MinimalStock'] ?? 0),
                            'is_locked' => in_array($stock['Locked'] ?? null, ['Y', 'tYES'], true),
                            'source_payload' => $stock,
                            'synced_at' => now(),
                        ],
                    );

                    $synchronizedStockIds[$warehouseStock->id] = true;
                    $processed++;
                }
            });

            $received = count($page);
            $skip += $received;
        } while ($received > 0);

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
