<?php

namespace App\Http\Controllers\Operations;

use App\Application\Purchasing\PurchaseRequestUsageHistory;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCostCenterCache;
use App\Models\SapItemCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SparepartHistoryController extends Controller
{
    public function __invoke(
        Request $request,
        SapCompanyContext $companies,
        PurchaseRequestUsageHistory $usageHistory,
    ): JsonResponse {
        $companyDb = $companies->current();
        $user = $request->user();
        $user?->loadMissing('operationalProfile');
        $warehouseCode = trim((string) $user?->operationalProfile?->warehouse_code);
        $validated = $request->validate([
            'item_ids' => ['nullable', 'array', 'max:50'],
            'item_ids.*' => [
                'integer',
                'distinct:strict',
                Rule::exists('sap_items_cache', 'id')->where(fn ($query) => $query
                    ->where('company_db', $companyDb)
                    ->where('is_active', true)),
            ],
            'vehicle_cost_center_id' => [
                'nullable',
                'integer',
                Rule::exists('sap_cost_center_caches', 'id')->where(fn ($query) => $query
                    ->where('company_db', $companyDb)
                    ->where('dimension', config('sap.dimensions.vehicle'))
                    ->where('is_active', true)),
            ],
        ]);

        $itemsById = SapItemCache::query()
            ->forCompany($companyDb)
            ->whereIn('id', $validated['item_ids'] ?? [])
            ->get()
            ->keyBy(fn (SapItemCache $item): string => (string) $item->id);
        $items = collect($validated['item_ids'] ?? [])
            ->map(fn (mixed $itemId): ?SapItemCache => $itemsById->get((string) $itemId))
            ->filter()
            ->values();
        $vehicle = isset($validated['vehicle_cost_center_id'])
            ? SapCostCenterCache::query()->forCompany($companyDb)->findOrFail($validated['vehicle_cost_center_id'])
            : null;
        $result = match (true) {
            $vehicle === null || $items->isEmpty() => ['histories' => [], 'warnings' => []],
            $warehouseCode === '' => [
                'histories' => [],
                'warnings' => ['Warehouse akun Anda belum dikonfigurasi. Histori pemakaian belum dapat dibaca.'],
            ],
            default => $usageHistory->capture($items, $vehicle->center_code, $warehouseCode, $companyDb),
        };

        return response()->json([
            'vehicle' => $vehicle ? ['code' => $vehicle->center_code, 'name' => $vehicle->center_name] : null,
            'histories' => $result['histories'],
            'warnings' => $result['warnings'],
        ]);
    }
}
