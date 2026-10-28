<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentApprovalController extends Controller
{
    public function __invoke(Request $request, SapCompanyContext $companies): View
    {
        $paymentRequests = SapDocument::query()
            ->forCompany($companies->current())
            ->where('document_type', 'PAYMENT_REQUEST')
            ->where('normalized_data->status', '<>', 'DRAFT')
            ->when(
                $request->string('payment_request_no')->trim()->isNotEmpty(),
                fn ($query) => $query->where('sap_doc_num', 'like', '%'.$request->string('payment_request_no')->trim()->toString().'%'),
            )
            ->when(
                $request->string('status')->trim()->isNotEmpty(),
                fn ($query) => $query->where('normalized_data->status', $request->string('status')->trim()->toString()),
            )
            ->when(
                $request->string('type')->trim()->isNotEmpty(),
                fn ($query) => $query->where('normalized_data->type', $request->string('type')->trim()->toString()),
            )
            ->when(
                $request->string('payment_means')->trim()->isNotEmpty(),
                fn ($query) => $query->where('normalized_data->payment_means', $request->string('payment_means')->trim()->toString()),
            )
            ->when(
                $request->string('currency')->trim()->isNotEmpty(),
                fn ($query) => $query->where('normalized_data->payment_currency', $request->string('currency')->trim()->toString()),
            )
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        return view('finance.payment-approvals.index', [
            'paymentRequests' => $paymentRequests,
        ]);
    }
}
