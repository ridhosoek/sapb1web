<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PaymentRequestController extends Controller
{
    public function index(SapCompanyContext $companies): View
    {
        $paymentRequests = SapDocument::query()
            ->forCompany($companies->current())
            ->where('document_type', 'PAYMENT_REQUEST')
            ->latest('updated_at')
            ->limit(20)
            ->get();

        return view('finance.payment-requests.index', [
            'paymentRequests' => $paymentRequests,
            'statusSummary' => $this->statusSummary($paymentRequests),
        ]);
    }

    public function create(SapCompanyContext $companies): View
    {
        $sourceInvoices = SapDocument::query()
            ->forCompany($companies->current())
            ->whereIn('document_type', ['AP_INVOICE', 'AP_DOWN_PAYMENT', 'AP_CREDIT_NOTE'])
            ->where('sap_business_status', 'OPEN')
            ->latest('updated_at')
            ->limit(100)
            ->get();

        return view('finance.payment-requests.create', [
            'sourceInvoices' => $sourceInvoices,
        ]);
    }

    /** @param Collection<int, SapDocument> $paymentRequests
     * @return array<string, int>
     */
    private function statusSummary(Collection $paymentRequests): array
    {
        $summary = [
            'DRAFT' => 0,
            'NEW' => 0,
            'APPROVED' => 0,
            'GENERATED' => 0,
        ];

        foreach ($paymentRequests as $paymentRequest) {
            $status = $paymentRequest->normalized_data['status'] ?? null;

            if (is_string($status) && array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }
}
