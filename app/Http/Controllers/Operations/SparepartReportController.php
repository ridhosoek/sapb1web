<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\Reports\SparepartExpenseGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCostCenterCache;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SparepartReportController extends Controller
{
    public function __invoke(
        Request $request,
        SapCompanyContext $companies,
        SparepartExpenseGateway $report,
    ): View {
        $companyDb = $companies->current();
        $user = $request->user();
        $user?->loadMissing('operationalProfile');
        $warehouseCode = trim((string) $user?->operationalProfile?->warehouse_code);
        $dateFromDefault = now()->subMonths(3)->startOfMonth()->toDateString();
        $dateToDefault = now()->toDateString();
        $filters = validator([
            ...$request->all(),
            'date_from' => $request->input('date_from', $dateFromDefault),
            'date_to' => $request->input('date_to', $dateToDefault),
        ], [
            'date_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:date_to'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:today'],
            'item_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::exists('sap_items_cache', 'item_code')->where(fn ($query) => $query
                    ->where('company_db', $companyDb)
                    ->where('is_active', true)),
            ],
            'nopol' => [
                'nullable',
                'string',
                'max:50',
                Rule::exists('sap_cost_center_caches', 'center_code')->where(fn ($query) => $query
                    ->where('company_db', $companyDb)
                    ->where('dimension', config('sap.dimensions.vehicle'))
                    ->where('is_active', true)),
            ],
        ], [
            'item_code.exists' => 'Item tidak aktif atau bukan milik database SAP yang sedang dipilih.',
            'nopol.exists' => 'Nopol tidak aktif atau bukan milik database SAP yang sedang dipilih.',
        ])->validate();

        $connectionError = $warehouseCode === ''
            ? 'Warehouse akun Anda belum dikonfigurasi. Hubungi administrator sebelum membuka report.'
            : null;
        $result = ['rows' => [], 'warnings' => []];

        if ($connectionError === null) {
            try {
                $cacheKey = 'sap:sparepart-report:'.hash('sha256', implode('|', [
                    $companyDb,
                    $warehouseCode,
                    $filters['date_from'],
                    $filters['date_to'],
                    $filters['item_code'] ?? '',
                    $filters['nopol'] ?? '',
                ]));
                $result = Cache::remember(
                    $cacheKey,
                    now()->addSeconds((int) config('sap.sparepart_report.cache_seconds', 300)),
                    fn (): array => $report->search(
                        dateFrom: $filters['date_from'],
                        dateTo: $filters['date_to'],
                        itemCode: $filters['item_code'] ?? null,
                        vehicleCode: $filters['nopol'] ?? null,
                        warehouseCode: $warehouseCode,
                        limit: (int) config('sap.sparepart_report.limit', 250),
                    ),
                );
            } catch (Throwable $exception) {
                report($exception);
                $connectionError = 'Report belum dapat dibaca dari SAP. Periksa koneksi Service Layer dan Integration Monitor.';
            }
        }

        $rows = collect($result['rows']);

        return view('operations.sparepart-report.index', [
            'companyDb' => $companyDb,
            'warehouseCode' => $warehouseCode,
            'filters' => $filters,
            'rows' => $rows,
            'warnings' => $result['warnings'],
            'connectionError' => $connectionError,
            'vehicles' => SapCostCenterCache::query()
                ->forCompany($companyDb)
                ->where('dimension', config('sap.dimensions.vehicle'))
                ->where('is_active', true)
                ->orderBy('center_code')
                ->get(),
            'summary' => [
                'transactions' => $rows->count(),
                'amount' => $rows->sum('amount'),
                'items' => $rows->pluck('item_code')->filter()->unique()->count(),
                'vehicles' => $rows->pluck('vehicle')->filter()->unique()->count(),
                'latest_date' => $rows->max('date'),
            ],
            'generatedAt' => Carbon::now(),
        ]);
    }
}
