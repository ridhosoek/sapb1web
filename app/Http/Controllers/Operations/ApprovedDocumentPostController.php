<?php

namespace App\Http\Controllers\Operations;

use App\Application\Approvals\PostApprovedDocument;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
use App\Http\Controllers\Controller;
use App\Models\SapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ApprovedDocumentPostController extends Controller
{
    public function __invoke(Request $request, int $document, PostApprovedDocument $post): RedirectResponse
    {
        $sapDocument = SapDocument::query()->with('approval')->findOrFail($document);

        abort_unless($request->user()->can('manage-approval-workflows'), 403);

        if ($sapDocument->integration_status === IntegrationStatus::PostingUnknown) {
            return back()->withErrors(['sap' => 'Status POST belum pasti. Rekonsiliasi ke SAP diperlukan sebelum mencoba ulang agar tidak membuat dokumen ganda.']);
        }

        try {
            $posted = $post->handle($sapDocument, $request->user());

            return back()->with('status', "Dokumen SAP {$posted->sap_doc_num} berhasil dibuat.");
        } catch (SapSubmissionException $exception) {
            return back()->withErrors(['sap' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['sap' => 'SAP belum dapat menerima dokumen. Sistem terjadwal akan mencoba lagi jika status POST masih aman untuk diulang.']);
        }
    }
}
