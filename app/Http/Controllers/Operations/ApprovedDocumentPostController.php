<?php

namespace App\Http\Controllers\Operations;

use App\Application\Approvals\PostApprovedDocument;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
use App\Http\Controllers\Controller;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ApprovedDocumentPostController extends Controller
{
    public function __invoke(Request $request, int $document, PostApprovedDocument $post, SapCompanyContext $companies): RedirectResponse
    {
        $sapDocument = SapDocument::query()->forCompany($companies->current())->with('approval')->findOrFail($document);

        abort_unless($request->user()->can('manage-approval-workflows'), 403);

        if ($sapDocument->integration_status === IntegrationStatus::PostingUnknown) {
            return back()->withErrors(['sap' => 'Status POST belum pasti. Rekonsiliasi ke SAP diperlukan sebelum mencoba ulang agar tidak membuat dokumen ganda.']);
        }

        if ($sapDocument->integration_status === IntegrationStatus::SapError) {
            $validated = $request->validate([
                'evidence' => ['required', 'string', 'min:20', 'max:1000'],
                'confirm_not_in_sap' => ['accepted'],
            ], [
                'confirm_not_in_sap.accepted' => 'Konfirmasikan bahwa dokumen belum tersimpan di SAP sebelum mengirim ulang.',
            ]);
            $command = SapCommand::query()
                ->where('company_db', $sapDocument->company_db)
                ->where('transaction_uuid', $sapDocument->transaction_uuid)
                ->first();

            if ($sapDocument->approval?->status !== 'APPROVED'
                || $command?->status !== IntegrationStatus::SapError
                || $sapDocument->sap_doc_entry !== null
                || $command->sap_doc_entry !== null) {
                return back()->withErrors(['sap' => 'Status dokumen dan command tidak cocok untuk kirim ulang. Periksa Integration Monitor.']);
            }

            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'event' => 'approved_document.manual_retry_requested',
                'auditable_type' => SapDocument::class,
                'auditable_id' => $sapDocument->id,
                'metadata' => [
                    'company_db' => $sapDocument->company_db,
                    'document_type' => $sapDocument->document_type,
                    'command_id' => $command->id,
                    'previous_status' => $command->status->value,
                    'previous_error' => $command->last_error,
                    'previous_attempts' => $command->attempts,
                    'evidence' => trim($validated['evidence']),
                ],
                'ip_address' => $request->ip(),
            ]);
        }

        try {
            $posted = $post->handle($sapDocument, $request->user());

            return back()->with('status', "Dokumen SAP {$posted->sap_doc_num} berhasil dibuat.");
        } catch (SapSubmissionException $exception) {
            return back()->withErrors(['sap' => $exception->getMessage()]);
        } catch (SapBusinessException $exception) {
            report($exception);

            if (! $exception->isDefiniteRejection()) {
                return back()->withErrors(['sap' => 'Hasil pengiriman ke SAP belum pasti (HTTP '.$exception->httpStatus.'). Periksa dokumen di SAP; jangan kirim ulang sebelum rekonsiliasi.']);
            }

            if ($exception->isCompanyConnectionFailure()) {
                return back()->withErrors(['sap' => $exception->getMessage().' Pesan SAP: '.$exception->technicalMessage]);
            }

            return back()->withErrors(['sap' => 'SAP menolak dokumen (status SAP_ERROR): '
                .($exception->isPostingPeriodRejection() ? $exception->getMessage().' Pesan SAP: ' : '')
                .($exception->technicalMessage ?: $exception->getMessage())
                .' Tanggal transaksi tidak diubah dan dokumen tidak dikirim ulang otomatis.']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['sap' => 'SAP belum dapat menerima dokumen. Sistem terjadwal akan mencoba lagi jika status POST masih aman untuk diulang.']);
        }
    }
}
