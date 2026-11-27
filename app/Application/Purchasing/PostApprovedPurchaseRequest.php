<?php

namespace App\Application\Purchasing;

use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Documents\PurchaseRequestGateway;
use App\Integration\Sap\Documents\PurchaseRequestPayloadBuilder;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Integration\Sap\SapSessionManager;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PostApprovedPurchaseRequest
{
    public function __construct(
        private readonly PurchaseRequestGateway $gateway,
        private readonly PurchaseRequestPayloadBuilder $payloads,
        private readonly SapCompanyContext $companies,
        private readonly SapSessionManager $sessions,
        private readonly RecordPostedPurchaseRequest $recordPostedPurchaseRequest,
    ) {}

    public function handle(SapDocument $document, User $approver): SapDocument
    {
        $command = SapCommand::query()->where('transaction_uuid', $document->transaction_uuid)->firstOrFail();

        if ($document->integration_status === IntegrationStatus::InSync) {
            return $document;
        }

        $this->companies->select($document->company_db);

        $document->loadMissing('approval.submitter.operationalProfile.pool', 'approval.submitter.operationalProfile.department');
        $data = $document->normalized_data ?? [];
        $submitter = $document->approval->submitter;
        $departmentCostCenter = SapCostCenterCache::query()->forCompany($document->company_db)->findOrFail((int) data_get($data, 'sap_context.department_cost_center.id'));
        $poolCostCenter = SapCostCenterCache::query()->forCompany($document->company_db)->findOrFail((int) data_get($data, 'sap_context.pool_cost_center.id'));
        $legacyVehicleCostCenterId = (int) data_get($data, 'vehicle_cost_center.id', 0);
        $legacyVehicleCostCenter = $legacyVehicleCostCenterId > 0
            ? SapCostCenterCache::query()->forCompany($document->company_db)->find($legacyVehicleCostCenterId)
            : null;
        $items = SapItemCache::query()
            ->forCompany($document->company_db)
            ->whereIn('id', collect($data['lines'] ?? [])->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $payload = $this->payloads->build($data, $submitter, $departmentCostCenter, $poolCostCenter, $legacyVehicleCostCenter, $items, [
            'warehouse_code' => $data['warehouse_code'] ?? null,
            'requester_email' => $data['requester_email'] ?? null,
            'created_by_web_user' => $data['created_by_web_user'] ?? $submitter->name,
        ]);
        $this->ensureTransactionUuidIsSentWhenRequired($document, $payload);
        // A login that fails here happens before anything is sent, so the document is not left as uncertain.
        $this->sessions->session();
        // The claim and the stored request are written together: a document is never POSTING without the payload that
        // a later reconciliation needs.
        $claimed = DB::transaction(function () use ($document, $command, $payload): bool {
            $claimed = SapDocument::query()
                ->whereKey($document->id)
                ->whereIn('integration_status', [IntegrationStatus::PendingPost, IntegrationStatus::SapError])
                ->update(['integration_status' => IntegrationStatus::Posting]) === 1;

            if ($claimed) {
                $command->update([
                    'request_payload' => $payload,
                    'status' => IntegrationStatus::Posting,
                    'attempts' => $command->attempts + 1,
                    'last_attempt_at' => now(),
                    'last_error' => null,
                ]);
            }

            return $claimed;
        });

        if (! $claimed) {
            $document->refresh();

            if ($document->integration_status === IntegrationStatus::InSync) {
                return $document;
            }

            throw new SapSubmissionException('Dokumen sedang diproses atau memerlukan rekonsiliasi sebelum dapat dikirim ulang.');
        }

        // The claim ran as a query. Keep the model in step, or a second SAP rejection of a resent document would
        // look unchanged (SAP_ERROR to SAP_ERROR) and leave the row stuck in POSTING.
        $document->integration_status = IntegrationStatus::Posting;
        $document->syncOriginalAttribute('integration_status');

        try {
            $response = $this->gateway->create($payload, $command->transaction_uuid);
            $docEntry = $this->requiredDocEntry($response);
        } catch (SapBusinessException $exception) {
            if ($exception->isDefiniteRejection()) {
                $command->update(['status' => IntegrationStatus::SapError, 'last_error' => $exception->technicalMessage ?? $exception->getMessage()]);
                $document->update(['integration_status' => IntegrationStatus::SapError]);
            } else {
                $this->markPostingUnknown($document, $command, $approver, "Hasil POST Purchase Request belum pasti (HTTP {$exception->httpStatus}). Periksa SAP sebelum mencoba ulang.", [
                    'stage' => 'SAP_RESPONSE',
                    'http_status' => $exception->httpStatus,
                ]);
            }

            throw $exception;
        } catch (Throwable $exception) {
            $reason = $exception instanceof ConnectionException
                ? 'Koneksi terputus saat POST; status SAP perlu direkonsiliasi.'
                : 'Respons POST tidak dapat dipastikan; status SAP perlu direkonsiliasi.';
            $this->markPostingUnknown($document, $command, $approver, $reason, [
                'stage' => 'SAP_RESPONSE',
                'exception' => class_basename($exception),
            ]);

            throw $exception;
        }

        $docNum = isset($response['DocNum']) ? (string) $response['DocNum'] : null;

        try {
            return $this->recordPostedPurchaseRequest->handle(
                document: $document,
                command: $command,
                docEntry: $docEntry,
                docNum: $docNum,
                actorId: $approver->id,
                auditEvent: 'purchase_request.posted_after_approval',
            );
        } catch (Throwable $exception) {
            $this->markPostingUnknown($document, $command, $approver, "SAP sudah menerima Purchase Request (DocEntry {$docEntry}), tetapi pencatatan di portal gagal; rekonsiliasi berdasarkan UUID diperlukan.", [
                'stage' => 'PORTAL_RECORDING',
                'exception' => class_basename($exception),
                'sap_doc_entry' => $docEntry,
                'sap_doc_num' => $docNum,
            ], ['sap_doc_entry' => $docEntry, 'sap_doc_num' => $docNum]);

            throw $exception;
        }
    }

    /**
     * Holds a POST whose result is not certain. It only moves a document that is still POSTING, so it never undoes a
     * result a reconciliation has already recorded, and a failure to write it never hides the original error. A row
     * left in POSTING is picked up by the reconciliation once it is stale.
     *
     * @param  array<string, mixed>  $auditMetadata
     * @param  array<string, mixed>  $sapIdentity  what SAP answered with, checked again by the reconciliation
     */
    private function markPostingUnknown(SapDocument $document, SapCommand $command, User $approver, string $reason, array $auditMetadata, array $sapIdentity = []): void
    {
        try {
            DB::transaction(function () use ($document, $command, $approver, $reason, $auditMetadata, $sapIdentity): void {
                $held = SapDocument::query()
                    ->whereKey($document->id)
                    ->where('integration_status', IntegrationStatus::Posting)
                    ->update(['integration_status' => IntegrationStatus::PostingUnknown]) === 1;

                if (! $held) {
                    return;
                }

                SapCommand::query()->whereKey($command->id)->update([
                    'status' => IntegrationStatus::PostingUnknown,
                    'last_error' => $reason,
                    ...$sapIdentity,
                ]);
                AuditLog::query()->create([
                    'actor_id' => $approver->id,
                    'event' => 'purchase_request.posting_unknown',
                    'auditable_type' => SapDocument::class,
                    'auditable_id' => $document->id,
                    'metadata' => [
                        'company_db' => $document->company_db,
                        'command_id' => $command->id,
                        'request_uuid' => $command->transaction_uuid,
                        ...$auditMetadata,
                    ],
                ]);
            });
        } catch (Throwable $recordingFailure) {
            report($recordingFailure);
        }
    }

    /**
     * Companies listed in the configuration must send the UUID that a reconciliation searches for. Off by default, so
     * a company whose field has not been verified is not blocked.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ensureTransactionUuidIsSentWhenRequired(SapDocument $document, array $payload): void
    {
        if (! in_array($document->company_db, config('sap.purchase_request.require_web_transaction_id_companies', []), true)) {
            return;
        }

        $field = trim((string) config('sap.fields.web_transaction_id'));

        if ($field === '' || data_get($payload, $field) !== $document->transaction_uuid) {
            throw new SapSubmissionException('Purchase Request belum dikirim: field UUID transaksi belum tersedia pada database SAP ini. Hubungi tim IT.');
        }
    }

    /** @param array<string, mixed> $response */
    private function requiredDocEntry(array $response): int
    {
        $docEntry = $response['DocEntry'] ?? null;

        if (! is_int($docEntry) && ! ctype_digit((string) $docEntry)) {
            throw new SapSubmissionException('SAP tidak mengembalikan DocEntry untuk Purchase Request.');
        }

        return (int) $docEntry;
    }
}
