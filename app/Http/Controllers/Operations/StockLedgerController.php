<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\Inventory\StockLedgerGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapItemWarehouseStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class StockLedgerController extends Controller
{
    public function __invoke(
        Request $request,
        int $stock,
        SapCompanyContext $companies,
        StockLedgerGateway $ledger,
    ): View {
        $warehouseCode = (string) $request->user()->operationalProfile->warehouse_code;
        $warehouseStock = SapItemWarehouseStock::query()
            ->forCompany($companies->current())
            ->forWarehouse($warehouseCode)
            ->with('item')
            ->whereKey($stock)
            ->firstOrFail();
        $ledgerError = null;

        try {
            $movements = Cache::store(config('cache.default'))->remember(
                'sap:stock-ledger:'.hash('sha256', $companies->current().'|'.$warehouseCode.'|'.$warehouseStock->item->item_code),
                now()->addSeconds((int) config('sap.stock.ledger_cache_seconds', 120)),
                fn (): array => $ledger->recent(
                    $warehouseStock->item->item_code,
                    $warehouseCode,
                    (int) config('sap.stock.ledger_limit', 100),
                ),
            );
        } catch (Throwable $exception) {
            report($exception);
            $movements = [];
            $ledgerError = 'Riwayat pergerakan dari SAP belum dapat dimuat. Snapshot stok terakhir tetap ditampilkan.';
        }

        return view('operations.stock-monitoring.show', [
            'stock' => $warehouseStock,
            'movements' => $movements,
            'ledgerError' => $ledgerError,
        ]);
    }
}
