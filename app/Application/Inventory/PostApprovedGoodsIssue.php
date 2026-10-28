<?php

namespace App\Application\Inventory;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Documents\GoodsIssueGateway;
use App\Integration\Sap\Documents\GoodsIssuePayloadBuilder;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\ControlVehicle;
use App\Models\SapCommand;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PostApprovedGoodsIssue
{
    public function __construct(
        private readonly GoodsIssueGateway $gateway,
        private readonly GoodsIssuePayloadBuilder $payloads,
        private readonly GoodsIssueStockService $stocks,
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
        $warehouseCode = (string) ($data['warehouse_code'] ?? '');
        $items = SapItemCache::query()
            ->forCompany($document->company_db)
            ->whereIn('id', collect($data['lines'] ?? [])->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $this->stocks->ensureAvailable($data, $document->company_db, $warehouseCode, $items);
        $submitter = $document->approval->submitter;
        $vehicleCode = $this->vehicleCode($data, $document->company_db);
        $departmentCostCenter = SapCostCenterCache::query()->forCompany($document->company_db)->findOrFail((int) data_get($data, 'sap_context.department_cost_center.id'));
        $poolCostCenter = SapCostCenterCache::query()->forCompany($document->company_db)->findOrFail((int) data_get($data, 'sap_context.pool_cost_center.id'));
        $payload = $this->payloads->build($data, $submitter, $vehicleCode, $departmentCostCenter, $poolCostCenter, $items, [
            'warehouse_code' => $warehouseCode,
            'pool_code' => $data['pool_code'] ?? null,
            'department_code' => $data['department_code'] ?? null,
            'account_code' => data_get($data, 'issue_type.account_code'),
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

            return DB::transaction(function () use ($document, $command, $approver, $data, $warehouseCode, $response, $docEntry): SapDocument {
                $normalizedData = $data;
                $normalizedData['approval_status'] = 'APPROVED';
                $document->update([
                    'sap_doc_entry' => $docEntry,
                    'sap_doc_num' => isset($response['DocNum']) ? (string) $response['DocNum'] : null,
                    'sap_business_status' => BusinessStatus::Open,
                    'integration_status' => IntegrationStatus::InSync,
                    'normalized_data' => $normalizedData,
                ]);
                $command->update([
                    'status' => IntegrationStatus::InSync,
                    'sap_doc_entry' => $docEntry,
                    'sap_doc_num' => $document->sap_doc_num,
                    'last_error' => null,
                ]);
                $this->stocks->decrement($data, $document->company_db, $warehouseCode);
                AuditLog::query()->create([
                    'actor_id' => $approver->id,
                    'event' => 'goods_issue.posted_after_approval',
                    'auditable_type' => SapDocument::class,
                    'auditable_id' => $document->id,
                    'metadata' => ['company_db' => $document->company_db, 'sap_doc_entry' => $docEntry, 'fpk_number' => $document->fpk_number],
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
            throw new SapSubmissionException('SAP tidak mengembalikan DocEntry untuk Goods Issue.');
        }

        return (int) $docEntry;
    }

    /** @param array<string, mixed> $data */
    private function vehicleCode(array $data, string $companyDb): string
    {
        $vehicleCode = trim((string) data_get($data, 'vehicle_cost_center.center_code'));

        if ($vehicleCode !== '') {
            return $vehicleCode;
        }

        $legacyVehicle = ControlVehicle::query()->find((int) data_get($data, 'vehicle.id'));

        if ($legacyVehicle) {
            return $legacyVehicle->license_plate;
        }

        throw new SapSubmissionException("Cost center Nopol untuk database {$companyDb} tidak ditemukan pada dokumen.");
    }
}
