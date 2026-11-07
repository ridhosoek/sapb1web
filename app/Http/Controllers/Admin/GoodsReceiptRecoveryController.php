<?php

namespace App\Http\Controllers\Admin;

use App\Application\Receiving\ReleaseUncertainGoodsReceipt;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCommand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoodsReceiptRecoveryController extends Controller
{
    public function __invoke(
        Request $request,
        SapCommand $command,
        SapCompanyContext $companies,
        ReleaseUncertainGoodsReceipt $release,
    ): RedirectResponse {
        abort_unless($command->company_db === $companies->current(), 404);

        $data = $request->validate([
            'evidence' => ['required', 'string', 'min:20', 'max:1000'],
            'confirm' => ['accepted'],
        ], [
            'evidence.required' => 'Tuliskan bukti investigasi otoritatif sebelum melepas command.',
            'evidence.min' => 'Bukti investigasi minimal 20 karakter.',
            'confirm.accepted' => 'Centang konfirmasi bahwa investigasi otoritatif membuktikan GRPO tidak tersimpan di SAP.',
        ]);
        $result = $release->handle($command, $request->user(), $data['evidence'], $request->ip());

        if ($result['outcome'] === 'reconciled') {
            return back()->with('status', 'GRPO ditemukan di SAP berdasarkan UUID dan sudah dicatat di portal (GRPO '.$result['goods_receipt']->sap_doc_num.'). Command tidak dilepas karena sudah selesai.');
        }

        $message = 'Command GRPO dilepas dan dicatat di audit. Penerimaan baru untuk PO ini kini dapat diproses dengan validasi live SAP.';

        if ($result['unlinked_goods_receipts'] !== []) {
            $message .= ' Perhatian: SAP memiliki GRPO yang belum tercatat di portal ('.implode(', ', $result['unlinked_goods_receipts']).'); pastikan bukan hasil command ini.';
        }

        return back()->with('status', $message);
    }
}
