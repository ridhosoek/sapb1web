<?php

namespace App\Http\Controllers\Lookup;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapItemCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemLookupController extends Controller
{
    public function __invoke(Request $request, SapCompanyContext $companies): JsonResponse
    {
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
                ->where('warehouse_stock.is_locked', false)
                ->where('warehouse_stock.available_stock', '>', 0);
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
            ],
        ]);
    }
}
