<?php

namespace App\Http\Controllers\Operations;

use App\Application\Purchasing\SyncPurchaseRequestProgress;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class PurchaseProgressSyncController extends Controller
{
    public function __invoke(
        int $document,
        SapCompanyContext $companies,
        SyncPurchaseRequestProgress $sync,
    ): RedirectResponse {
        $purchaseRequest = SapDocument::query()
            ->forCompany($companies->current())
            ->where('document_type', 'PURCHASE_REQUEST')
            ->whereKey($document)
            ->firstOrFail();
        Gate::authorize('view', $purchaseRequest);

        try {
            $result = $sync->handle($purchaseRequest);

            if ($result['goods_receipt_found']) {
                $numbers = $result['goods_receipt_doc_nums'] === [] ? 'tanpa DocNum' : implode(', ', $result['goods_receipt_doc_nums']);

                return to_route('purchase-progress.index')
                    ->with('status', "Sinkronisasi selesai. Goods Receipt PO {$numbers} ditemukan di SAP.");
            }

            if ($result['purchase_order_found']) {
                $numbers = $result['purchase_order_doc_nums'] === [] ? 'tanpa DocNum' : implode(', ', $result['purchase_order_doc_nums']);

                return to_route('purchase-progress.index')
                    ->with('status', "Sinkronisasi selesai. Purchase Order {$numbers} ditemukan di SAP.");
            }

            if ($result['quotation_found']) {
                $numbers = $result['quotation_doc_nums'] === [] ? 'tanpa DocNum' : implode(', ', $result['quotation_doc_nums']);

                return to_route('purchase-progress.index')
                    ->with('status', "Sinkronisasi selesai. Purchase Quotation {$numbers} ditemukan; Purchase Order belum ditemukan di SAP.");
            }

            return to_route('purchase-progress.index')
                ->with('status', 'Sinkronisasi selesai. Purchase Quotation yang mereferensikan PR ini belum ditemukan di SAP.');
        } catch (Throwable $exception) {
            report($exception);

            return to_route('purchase-progress.index')
                ->withErrors(['sap' => 'Sinkronisasi ke SAP gagal. Sistem akan mencoba kembali secara otomatis.']);
        }
    }
}
