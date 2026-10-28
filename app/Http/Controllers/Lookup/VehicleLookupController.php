<?php

namespace App\Http\Controllers\Lookup;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCostCenterCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleLookupController extends Controller
{
    public function __invoke(Request $request, SapCompanyContext $companies): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $term = trim($validated['q']);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 30);

        $vehicles = SapCostCenterCache::query()
            ->forCompany($companies->current())
            ->where('dimension', config('sap.dimensions.vehicle'))
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
            ],
        ]);
    }
}
