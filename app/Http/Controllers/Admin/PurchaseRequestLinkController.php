<?php

namespace App\Http\Controllers\Admin;

use App\Application\Purchasing\LinkPurchaseRequestToSap;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** TD-14: a Super Admin links a portal Purchase Request to the SAP document they verified. Nothing is posted. */
class PurchaseRequestLinkController extends Controller
{
    public function show(Request $request, int $document, SapCompanyContext $companies, LinkPurchaseRequestToSap $link): View
    {
        $purchaseRequest = $this->purchaseRequest($document, $companies);
        $data = $request->validate($this->identityRules());

        return view('admin.purchase-requests.link', [
            'document' => $purchaseRequest,
            'docEntry' => (int) $data['doc_entry'],
            'docNum' => (string) $data['doc_num'],
            'review' => $link->review($purchaseRequest, (int) $data['doc_entry'], (string) $data['doc_num']),
        ]);
    }

    public function store(Request $request, int $document, SapCompanyContext $companies, LinkPurchaseRequestToSap $link): RedirectResponse
    {
        $purchaseRequest = $this->purchaseRequest($document, $companies);
        $data = $request->validate([
            ...$this->identityRules(),
            'reviewed_candidates' => ['nullable', 'array'],
            'reviewed_candidates.*' => ['integer', 'min:1'],
            'reason' => ['required', 'string', 'min:'.LinkPurchaseRequestToSap::MIN_REASON_LENGTH, 'max:1000'],
            'confirm' => ['accepted'],
        ], [
            'reason.required' => 'Tuliskan alasan memilih DocEntry ini.',
            'reason.min' => 'Alasan minimal :min karakter.',
            'confirm.accepted' => 'Centang konfirmasi bahwa dokumen SAP ini sudah Anda verifikasi.',
        ]);
        $result = $link->link(
            $purchaseRequest,
            (int) $data['doc_entry'],
            (string) $data['doc_num'],
            array_map('intval', $data['reviewed_candidates'] ?? []),
            $data['reason'],
            $request->user(),
            $request->ip(),
        );

        if ($result['linked']) {
            return to_route('documents.show', $purchaseRequest)
                ->with('status', "Purchase Request sudah ditautkan ke SAP DocEntry {$data['doc_entry']} (DocNum {$data['doc_num']}). Tidak ada dokumen yang dikirim ke SAP.");
        }

        return to_route('admin.purchase-requests.link', [
            'document' => $purchaseRequest->id,
            'doc_entry' => $data['doc_entry'],
            'doc_num' => $data['doc_num'],
        ])->withErrors(['link' => $result['problems']])->withInput();
    }

    private function purchaseRequest(int $document, SapCompanyContext $companies): SapDocument
    {
        return SapDocument::query()
            ->forCompany($companies->current())
            ->where('document_type', 'PURCHASE_REQUEST')
            ->findOrFail($document);
    }

    /** @return array<string, list<string>> */
    private function identityRules(): array
    {
        return [
            'doc_entry' => ['required', 'integer', 'min:1'],
            'doc_num' => ['required', 'digits_between:1,20'],
        ];
    }
}
