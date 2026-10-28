<?php

namespace App\Application\Purchasing;

use App\Integration\Sap\Reports\SparepartExpenseGateway;
use App\Models\SapItemCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PurchaseRequestUsageHistory
{
    public function __construct(private readonly SparepartExpenseGateway $report) {}

    /**
     * @param  Collection<int, SapItemCache>  $items
     * @return array{histories: list<array<string, mixed>>, warnings: list<string>}
     */
    public function capture(Collection $items, string $vehicleCode, string $companyDb): array
    {
        $capturedAt = now();
        $dateFrom = (string) config('sap.sparepart_report.usage_history_start_date', '2000-01-01');
        $dateTo = $capturedAt->toDateString();
        $warnings = [];

        try {
            $result = Cache::remember(
                'sap:pr-usage-history:'.hash('sha256', $companyDb.'|'.$vehicleCode.'|'.$dateFrom.'|'.$dateTo),
                $capturedAt->copy()->addSeconds((int) config('sap.sparepart_report.cache_seconds', 300)),
                fn (): array => $this->report->search(
                    dateFrom: $dateFrom,
                    dateTo: $dateTo,
                    vehicleCode: $vehicleCode,
                    limit: (int) config('sap.sparepart_report.usage_history_limit', 1000),
                    sources: ['goods_issue'],
                    withSerials: false,
                ),
            );
            $warnings = $result['warnings'];
            $rows = collect($result['rows'])
                ->whereIn('item_code', $items->pluck('item_code'))
                ->unique('item_code')
                ->keyBy('item_code');
        } catch (Throwable $exception) {
            report($exception);
            $warnings = ['Histori Goods Issue SAP sedang tidak dapat dibaca. Snapshot disimpan dengan status tidak tersedia.'];
            $rows = collect();
        }

        $histories = $items->map(function (SapItemCache $item) use ($rows, $vehicleCode, $capturedAt, $warnings): array {
            $row = $rows->get($item->item_code);
            $status = $row ? 'FOUND' : ($warnings === [] ? 'NOT_FOUND' : 'UNAVAILABLE');
            $goodsIssueDate = is_array($row) ? ($row['date'] ?? null) : null;

            return [
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'vehicle_code' => $vehicleCode,
                'status' => $status,
                'last_goods_issue_date' => $goodsIssueDate,
                'days_in_use' => filled($goodsIssueDate)
                    ? max(0, (int) Carbon::parse((string) $goodsIssueDate)->startOfDay()->diffInDays($capturedAt->copy()->startOfDay(), false))
                    : null,
                'quantity' => is_array($row) ? (float) ($row['quantity'] ?? 0) : null,
                'unit_price' => is_array($row) ? (float) ($row['unit_price'] ?? 0) : null,
                'expense_amount' => is_array($row) ? (float) ($row['amount'] ?? 0) : null,
                'currency' => is_array($row) ? (string) ($row['currency'] ?? 'IDR') : 'IDR',
                'fpk' => is_array($row) ? ($row['fpk'] ?? null) : null,
                'sap_document_number' => is_array($row) ? ($row['document_number'] ?? null) : null,
                'captured_at' => $capturedAt->toIso8601String(),
            ];
        })->values()->all();

        return [
            'histories' => $histories,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
