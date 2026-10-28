<?php

namespace App\Application\Dashboard;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapDocument;
use App\Models\SapItemWarehouseStock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class GetOperationalDashboard
{
    public function __construct(private readonly SapCompanyContext $companies) {}

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $profile = $user->operationalProfile;
        $documents = SapDocument::query()
            ->forCompany($this->companies->current())
            ->visibleTo($user);
        $stockThreshold = (float) config('sap.stock.low_threshold', 5);
        $warehouseStocks = SapItemWarehouseStock::query()
            ->forCompany($this->companies->current())
            ->forWarehouse($profile->warehouse_code ?: '')
            ->where('is_locked', false)
            ->whereHas('item', fn (Builder $query) => $query
                ->where('is_active', true)
                ->where('is_inventory_item', true));

        return [
            'kpis' => [
                'open_pr' => (clone $documents)->where('document_type', 'PURCHASE_REQUEST')
                    ->where('sap_business_status', BusinessStatus::Open->value)->count(),
                'waiting_po' => (clone $documents)->where('normalized_data->progress_status', 'WAITING_PO')->count(),
                'ready_grpo' => (clone $documents)->where('normalized_data->progress_status', 'READY_FOR_GRPO')->count(),
                'goods_issue_month' => (clone $documents)->where('document_type', 'GOODS_ISSUE')
                    ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
                'stock_out' => (clone $warehouseStocks)->where('available_stock', '<=', 0)->count(),
                'stock_low' => $this->lowStockQuery(clone $warehouseStocks, $stockThreshold)->count(),
            ],
            'actions' => (clone $documents)
                ->whereIn('normalized_data->progress_status', ['WAITING_PO', 'READY_FOR_GRPO', 'PARTIALLY_RECEIVED'])
                ->latest('updated_at')
                ->limit(6)
                ->get(),
            'activity' => AuditLog::query()
                ->where('metadata->company_db', $this->companies->current())
                ->when(! $user->role->canViewAllDocuments(), fn (Builder $query) => $query
                    ->where(function (Builder $query) use ($profile, $user): void {
                        $query->where('metadata->pool_id', $profile->pool_id)
                            ->orWhere('actor_id', $user->id);
                    }))
                ->latest('created_at')
                ->limit(8)
                ->get(),
            'stockAlerts' => $this->alertStockQuery(clone $warehouseStocks, $stockThreshold)
                ->with('item:id,item_code,item_name,inventory_uom')
                ->orderBy('available_stock')
                ->limit(5)
                ->get(),
            'warehouseCode' => $profile->warehouse_code,
        ];
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

    private function alertStockQuery(Builder $query, float $threshold): Builder
    {
        return $query->where(function (Builder $query) use ($threshold): void {
            $query->where(function (Builder $query): void {
                $query->where('minimum_stock', '>', 0)
                    ->whereColumn('available_stock', '<=', 'minimum_stock');
            })->orWhere(function (Builder $query) use ($threshold): void {
                $query->where('minimum_stock', '<=', 0)
                    ->where('available_stock', '<=', $threshold);
            });
        });
    }
}
