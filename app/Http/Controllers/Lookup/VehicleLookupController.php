<?php

namespace App\Http\Controllers\Lookup;

use App\Application\CostCenters\SyncSapCostCenters;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCostCenterCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class VehicleLookupController extends Controller
{
    public function __invoke(
        Request $request,
        SapCompanyContext $companies,
        SyncSapCostCenters $syncCostCenters,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $term = trim($validated['q']);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 30);
        $companyDb = $companies->current();
        $vehicleDimension = (int) config('sap.dimensions.vehicle');
        $cacheRecovered = false;
        $hasVehicleCache = SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', $vehicleDimension)
            ->where('is_active', true)
            ->exists();
        $refreshKey = 'sap:vehicle-cost-centers-empty-refresh:'.hash('sha256', $companyDb.':'.$vehicleDimension);

        if (! $hasVehicleCache && Cache::add($refreshKey, true, now()->addMinutes(5))) {
            try {
                $result = $syncCostCenters->handle(
                    dimension: $vehicleDimension,
                    limit: 100000,
                    pageSize: 100,
                );
                $cacheRecovered = $result['processed'] > 0;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $vehicles = SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', $vehicleDimension)
            ->where('is_active', true)
            ->where(function ($query) use ($term): void {
                $query->where('center_code', 'like', '%'.$term.'%')
                    ->orWhere('center_name', 'like', '%'.$term.'%');
            })
            ->orderBy('center_code')
            ->orderBy('id')
            ->simplePaginate(
                perPage: $perPage,
                columns: ['id', 'center_code', 'center_name'],
                pageName: 'page',
                page: $page,
            );

        return response()->json([
            'data' => $vehicles->items(),
            'meta' => [
                'current_page' => $vehicles->currentPage(),
                'has_more' => $vehicles->hasMorePages(),
                'per_page' => $vehicles->perPage(),
                'cache_recovered' => $cacheRecovered,
            ],
        ]);
    }
}
