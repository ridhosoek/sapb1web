<?php

namespace App\Application\Purchasing;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\WatchStatus;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Documents\PurchaseRequestGateway;
use App\Integration\Sap\Documents\PurchaseRequestPayloadBuilder;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
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
        $vehicleCostCenter = SapCostCenterCache::query()->forCompany($document->company_db)->findOrFail((int) data_get($data, 'vehicle_cost_center.id'));
        $items = SapItemCache::query()
            ->forCompany($document->company_db)
            ->whereIn('id', collect($data['lines'] ?? [])->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $payload = $this->payloads->build($data, $submitter, $departmentCostCenter, $poolCostCenter, $vehicleCostCenter, $items, [
            'warehouse_code' => $data['warehouse_code'] ?? null,
            'requester_email' => $data['requester_email'] ?? null,
            'created_by_web_user' => $data['created_by_web_user'] ?? $submitter->name,
        ]);
        $claimed = SapDocument::query()
            ->whereKey($document->id)
            ->whereIn('integration_status', [IntegrationStatus::PendingPost, IntegrationStatus::SapError])
            ->update(['integration_status' => IntegrationStatus::Posting]);

        if ($claimed !== 1) {
            $document->refresh();

            if ($document->integration_status === IntegrationStatus::InSync) {
                return $document;
            }

            throw new SapSubmissionException('Dokumen sedang diproses atau memerlukan rekonsiliasi sebelum dapat dikirim ulang.');
        }

        $command->update([
            'request_payload' => $payload,
            'status' => IntegrationStatus::Posting,
            'attempts' => $command->attempts + 1,
            'last_attempt_at' => now(),
            'last_error' => null,
        ]);

        try {
            $response = $this->gateway->create($payload);
            $docEntry = $this->requiredDocEntry($response);

            return DB::transaction(function () use ($document, $command, $approver, $response, $docEntry): SapDocument {
                $data = $document->normalized_data ?? [];
                $data['approval_status'] = 'APPROVED';
                $data['progress_status'] = 'WAITING_QUOTATION';
                $document->update([
                    'sap_doc_entry' => $docEntry,
                    'sap_doc_num' => isset($response['DocNum']) ? (string) $response['DocNum'] : null,
                    'sap_business_status' => BusinessStatus::Open,
                    'integration_status' => IntegrationStatus::InSync,
                    'normalized_data' => $data,
                ]);
                $document->watcher()->updateOrCreate([], [
                    'watch_status' => WatchStatus::Active,
                    'next_poll_at' => now(),
                    'consecutive_failures' => 0,
                    'last_error' => null,
                ]);
                $command->update([
                    'status' => IntegrationStatus::InSync,
                    'sap_doc_entry' => $docEntry,
                    'sap_doc_num' => $document->sap_doc_num,
                    'last_error' => null,
                ]);
                AuditLog::query()->create([
                    'actor_id' => $approver->id,
                    'event' => 'purchase_request.posted_after_approval',
                    'auditable_type' => SapDocument::class,
                    'auditable_id' => $document->id,
                    'metadata' => ['company_db' => $document->company_db, 'sap_doc_entry' => $docEntry],
                ]);

                return $document->fresh();
            });
        } catch (SapBusinessException $exception) {
            $command->update(['status' => IntegrationStatus::SapError, 'last_error' => $exception->technicalMessage ?? $exception->getMessage()]);
            $document->update(['integration_status' => IntegrationStatus::SapError]);
            throw $exception;
        } catch (ConnectionException $exception) {
            $command->update(['status' => IntegrationStatus::PostingUnknown, 'last_error' => 'Koneksi terputus saat POST; status SAP perlu direkonsiliasi.']);
            $document->update(['integration_status' => IntegrationStatus::PostingUnknown]);
            throw $exception;
        } catch (Throwable $exception) {
            $command->update(['status' => IntegrationStatus::PostingUnknown, 'last_error' => 'Respons POST tidak dapat dipastikan; status SAP perlu direkonsiliasi.']);
            $document->update(['integration_status' => IntegrationStatus::PostingUnknown]);
            throw $exception;
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
