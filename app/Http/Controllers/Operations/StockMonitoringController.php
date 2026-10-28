<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapItemWarehouseStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockMonitoringController extends Controller
{
    public function __invoke(Request $request, SapCompanyContext $companies): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['OUT', 'LOW', 'HEALTHY'])],
        ]);
        $warehouseCode = $request->user()->operationalProfile->warehouse_code;
        $threshold = (float) config('sap.stock.low_threshold', 5);
        $baseQuery = SapItemWarehouseStock::query()
            ->forCompany($companies->current())
            ->forWarehouse($warehouseCode ?: '')
            ->where('is_locked', false)
            ->whereHas('item', fn (Builder $query) => $query
                ->where('is_active', true)
                ->where('is_inventory_item', true));

        $summary = [
            'total' => (clone $baseQuery)->count(),
            'available' => (clone $baseQuery)->where('available_stock', '>', 0)->count(),
            'out' => (clone $baseQuery)->where('available_stock', '<=', 0)->count(),
            'low' => $this->lowStockQuery(clone $baseQuery, $threshold)->count(),
            'ordered' => (float) (clone $baseQuery)->sum('ordered_stock'),
            'last_synced_at' => (clone $baseQuery)->max('synced_at'),
        ];

        $stocks = $baseQuery
            ->with('item:id,item_code,item_name,inventory_uom')
            ->when(filled($validated['q'] ?? null), function (Builder $query) use ($validated): void {
                $term = (string) $validated['q'];
                $query->whereHas('item', fn (Builder $itemQuery) => $itemQuery->search($term));
            })
            ->when(($validated['status'] ?? null) === 'OUT', fn (Builder $query) => $query->where('available_stock', '<=', 0))
            ->when(($validated['status'] ?? null) === 'LOW', fn (Builder $query) => $this->lowStockQuery($query, $threshold))
            ->when(($validated['status'] ?? null) === 'HEALTHY', fn (Builder $query) => $this->healthyStockQuery($query, $threshold))
            ->orderBy('available_stock')
            ->orderBy('sap_item_cache_id')
            ->paginate(50)
            ->withQueryString();

        return view('operations.stock-monitoring.index', [
            'stocks' => $stocks,
            'summary' => $summary,
            'warehouseCode' => $warehouseCode,
            'threshold' => $threshold,
        ]);
    }

    private function lowStockQuery(Builder $query, float $threshold): Builder
    {
        return $query
            ->where('available_stock', '>', 0)
            ->where(function (Builder $query) use ($threshold): void {
                $query->where(function (Builder $query): void {
                    $query->where('minimum_stock', '>', 0)
                        ->whereColumn('available_stock', '<=', 'minimum_stock');
                })->orWhere(function (Builder $query) use ($threshold): void {
                    $query->where('minimum_stock', '<=', 0)
                        ->where('available_stock', '<=', $threshold);
                });
            });
    }

    private function healthyStockQuery(Builder $query, float $threshold): Builder
    {
        return $query
            ->where('available_stock', '>', 0)
            ->where(function (Builder $query) use ($threshold): void {
                $query->where(function (Builder $query): void {
                    $query->where('minimum_stock', '>', 0)
                        ->whereColumn('available_stock', '>', 'minimum_stock');
                })->orWhere(function (Builder $query) use ($threshold): void {
                    $query->where('minimum_stock', '<=', 0)
                        ->where('available_stock', '>', $threshold);
                });
            });
    }
}
