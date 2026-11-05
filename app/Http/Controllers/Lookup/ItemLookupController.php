<?php

namespace App\Http\Controllers\Lookup;

use App\Application\Items\SyncSapItems;
use App\Application\Items\SyncSelectedSapItems;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

class ItemLookupController extends Controller
{
    public function __invoke(
        Request $request,
        SapCompanyContext $companies,
        SyncSapItems $syncItems,
        SyncSelectedSapItems $syncSelectedItems,
    ): JsonResponse|RedirectResponse {
        if ($request->header('Sec-Fetch-Mode') === 'navigate') {
            $route = $request->query('context') === 'purchase-request'
                ? 'purchase-requests.create'
                : 'goods-issues.create';

            return to_route($route)->with(
                'warning',
                'Pencarian item digunakan langsung dari form transaksi. Silakan pilih item dari kolom pencarian di halaman ini.',
            );
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'context' => ['nullable', Rule::in(['purchase-request', 'goods-issue'])],
        ]);
        $term = trim($validated['q'] ?? '');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 30);
        $context = $validated['context'] ?? 'goods-issue';
        $companyDb = $companies->current();
        $warehouseCode = $request->user()->operationalProfile->warehouse_code;
        $warehouseCacheRecovered = false;
        $masterCacheRecovered = false;

        if ($context === 'purchase-request') {
            if (mb_strlen($term) >= 2) {
                $searchRefreshKey = 'sap:purchase-items-search-refresh:'.hash('sha256', $companyDb.'|'.mb_strtolower($term));

                if (Cache::add($searchRefreshKey, true, now()->addMinutes(2))) {
                    try {
                        $masterCacheRecovered = $syncSelectedItems->searchActivePurchaseItems($term)->isNotEmpty();
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }

            $hasPurchaseItemCache = SapItemCache::query()
                ->forCompany($companyDb)
                ->where('is_active', true)
                ->where('is_purchase_item', true)
                ->exists();
            $refreshKey = 'sap:purchase-items-empty-refresh:'.hash('sha256', $companyDb);

            if (! $hasPurchaseItemCache && Cache::add($refreshKey, true, now()->addMinutes(5))) {
                try {
                    $result = $syncItems->handle(limit: 100000, pageSize: 100);
                    $masterCacheRecovered = $result['processed'] > 0;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        if ($context === 'goods-issue' && filled($warehouseCode)) {
            $hasWarehouseCache = SapItemWarehouseStock::query()
                ->forCompany($companyDb)
                ->forWarehouse($warehouseCode)
                ->exists();

            $refreshKey = 'sap:warehouse-stock-empty-refresh:'.hash('sha256', "{$companyDb}|{$warehouseCode}");

            if (! $hasWarehouseCache && Cache::add($refreshKey, true, now()->addMinutes(5))) {
                try {
                    $result = $syncItems->synchronizeWarehouses([$warehouseCode]);
                    $warehouseCacheRecovered = $result['processed'] > 0;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        $query = SapItemCache::query()
            ->where('sap_items_cache.company_db', $companyDb)
            ->where('sap_items_cache.is_active', true)
            ->when($context === 'purchase-request', fn ($query) => $query->where('sap_items_cache.is_purchase_item', true));

        if ($context === 'goods-issue') {
            $query
                ->where('sap_items_cache.is_inventory_item', true)
                ->join('sap_item_warehouse_stocks as warehouse_stock', 'warehouse_stock.sap_item_cache_id', '=', 'sap_items_cache.id')
                ->where('warehouse_stock.company_db', $companyDb)
                ->where('warehouse_stock.warehouse_code', $warehouseCode ?: '')
                ->where('warehouse_stock.available_stock', '>', 0)
                ->where('warehouse_stock.is_locked', false);
        }

        if ($term !== '') {
            $query->search($term);
        }

        $items = $query
            ->orderBy('sap_items_cache.item_code')
            ->orderBy('sap_items_cache.id')
            ->simplePaginate(
                perPage: $perPage,
                columns: $context === 'goods-issue'
                    ? [
                        'sap_items_cache.id',
                        'sap_items_cache.item_code',
                        'sap_items_cache.item_name',
                        'sap_items_cache.inventory_uom',
                        'warehouse_stock.in_stock',
                        'warehouse_stock.committed_stock',
                        'warehouse_stock.available_stock',
                    ]
                    : ['sap_items_cache.id', 'item_code', 'item_name', 'inventory_uom'],
                pageName: 'page',
                page: $page,
            );

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'has_more' => $items->hasMorePages(),
                'per_page' => $items->perPage(),
                'warehouse_cache_recovered' => $warehouseCacheRecovered,
                'master_cache_recovered' => $masterCacheRecovered,
            ],
        ]);
    }
}
