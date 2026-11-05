<?php

namespace App\Http\Controllers\Operations;

use App\Application\Purchasing\PurchaseRequestUsageHistory;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCostCenterCache;
use App\Models\SapItemCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SparepartHistoryController extends Controller
{
    public function __invoke(
        Request $request,
        SapCompanyContext $companies,
        PurchaseRequestUsageHistory $usageHistory,
    ): JsonResponse|RedirectResponse {
        if (! $request->expectsJson() && ! $request->ajax()) {
            return to_route('purchase-requests.create')->with(
                'warning',
                'Histori sparepart dimuat otomatis di dalam form Purchase Request. Silakan lanjutkan pengisian dari halaman ini.',
            );
        }

        $companyDb = $companies->current();
        $user = $request->user();
        $user?->loadMissing('operationalProfile');
        $warehouseCode = trim((string) $user?->operationalProfile?->warehouse_code);
        $validated = $request->validate([
            'lines' => ['nullable', 'array', 'max:50'],
            'lines.*.item_id' => [
                'required_with:lines',
                'integer',
                Rule::exists('sap_items_cache', 'id')->where(fn ($query) => $query
                    ->where('company_db', $companyDb)
                    ->where('is_active', true)),
            ],
            'lines.*.vehicle_cost_center_id' => [
                'nullable',
                'integer',
                Rule::exists('sap_cost_center_caches', 'id')->where(fn ($query) => $query
                    ->where('company_db', $companyDb)
                    ->where('dimension', config('sap.dimensions.vehicle'))
                    ->where('is_active', true)),
            ],
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

        $requestedLines = collect($validated['lines'] ?? []);

        if ($requestedLines->isEmpty()) {
            $requestedLines = collect($validated['item_ids'] ?? [])->map(fn (mixed $itemId): array => [
                'item_id' => (int) $itemId,
                'vehicle_cost_center_id' => $validated['vehicle_cost_center_id'] ?? null,
            ]);
        }

        $itemsById = SapItemCache::query()
            ->forCompany($companyDb)
            ->whereIn('id', $requestedLines->pluck('item_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $vehiclesById = SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->whereIn('id', $requestedLines->pluck('vehicle_cost_center_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $capturedHistories = collect();
        $warnings = [];

        if ($warehouseCode === '' && $requestedLines->contains(fn (array $line): bool => filled($line['vehicle_cost_center_id'] ?? null))) {
            $warnings[] = 'Warehouse akun Anda belum dikonfigurasi. Histori pemakaian belum dapat dibaca.';
        } elseif ($warehouseCode !== '') {
            $requestedLines
                ->filter(fn (array $line): bool => filled($line['vehicle_cost_center_id'] ?? null))
                ->groupBy(fn (array $line): int => (int) $line['vehicle_cost_center_id'])
                ->each(function ($lines, int $vehicleId) use ($vehiclesById, $itemsById, $usageHistory, $warehouseCode, $companyDb, $capturedHistories, &$warnings): void {
                    $vehicle = $vehiclesById->get($vehicleId);

                    if (! $vehicle) {
                        return;
                    }

                    $items = $lines
                        ->pluck('item_id')
                        ->map(fn (mixed $itemId): ?SapItemCache => $itemsById->get((int) $itemId))
                        ->filter()
                        ->unique('id')
                        ->values();
                    $result = $usageHistory->capture($items, $vehicle->center_code, $warehouseCode, $companyDb);
                    $warnings = [...$warnings, ...$result['warnings']];

                    foreach ($result['histories'] as $history) {
                        $capturedHistories->put($vehicleId.':'.(int) $history['item_id'], $history);
                    }
                });
        }

        $histories = $requestedLines->map(function (array $line, int $index) use ($itemsById, $vehiclesById, $capturedHistories): array {
            $item = $itemsById->get((int) $line['item_id']);
            $vehicleId = filled($line['vehicle_cost_center_id'] ?? null)
                ? (int) $line['vehicle_cost_center_id']
                : null;
            $vehicle = $vehicleId === null ? null : $vehiclesById->get($vehicleId);
            $history = $vehicleId === null
                ? null
                : $capturedHistories->get($vehicleId.':'.(int) $line['item_id']);

            return $history ?? [
                'line_index' => $index,
                'item_id' => $item?->id,
                'item_code' => $item?->item_code,
                'item_name' => $item?->item_name,
                'vehicle_code' => $vehicle?->center_code,
                'status' => $vehicle === null ? 'NO_VEHICLE' : 'UNAVAILABLE',
                'last_goods_issue_date' => null,
                'days_in_use' => null,
                'quantity' => null,
                'unit_price' => null,
                'expense_amount' => null,
                'currency' => 'IDR',
                'fpk' => null,
                'sap_document_number' => null,
                'captured_at' => now()->toIso8601String(),
            ];
        })->values()->all();
        $legacyVehicle = isset($validated['vehicle_cost_center_id'])
            ? $vehiclesById->get((int) $validated['vehicle_cost_center_id'])
            : null;

        return response()->json([
            'vehicle' => $legacyVehicle ? ['code' => $legacyVehicle->center_code, 'name' => $legacyVehicle->center_name] : null,
            'histories' => $histories,
            'warnings' => array_values(array_unique($warnings)),
        ]);
    }
}
