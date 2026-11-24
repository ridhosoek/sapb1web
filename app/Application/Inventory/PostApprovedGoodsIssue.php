<?php

namespace App\Application\Inventory;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
use App\Exceptions\SerialSelectionStaleException;
use App\Integration\Sap\Documents\GoodsIssueGateway;
use App\Integration\Sap\Documents\GoodsIssuePayloadBuilder;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class PostApprovedGoodsIssue
{
    public function __construct(
        private readonly GoodsIssueGateway $gateway,
        private readonly GoodsIssuePayloadBuilder $payloads,
        private readonly GoodsIssueStockService $stocks,
        private readonly GoodsIssueAccountResolver $accounts,
        private readonly GoodsIssueSerialSelection $serialSelection,
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
        $postingData = $this->withAccountCodes($data, $document->company_db);
        $warehouseCode = (string) ($data['warehouse_code'] ?? '');
        $items = SapItemCache::query()
            ->forCompany($document->company_db)
            ->whereIn('id', collect($data['lines'] ?? [])->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $this->stocks->ensureLiveAvailable($data, $warehouseCode, $items);
        $this->ensureSerialsStillAvailable($document, $command, $approver, $data, $items, $warehouseCode);
        $submitter = $document->approval->submitter;
        $legacyVehicleCode = $this->legacyVehicleCode($data);
        $departmentCostCenter = SapCostCenterCache::query()->forCompany($document->company_db)->findOrFail((int) data_get($data, 'sap_context.department_cost_center.id'));
        $poolCostCenter = SapCostCenterCache::query()->forCompany($document->company_db)->findOrFail((int) data_get($data, 'sap_context.pool_cost_center.id'));
        $payload = $this->payloads->build($postingData, $submitter, $legacyVehicleCode, $departmentCostCenter, $poolCostCenter, $items, [
            'warehouse_code' => $warehouseCode,
            'pool_code' => $data['pool_code'] ?? null,
            'pool_name' => $submitter->operationalProfile->pool->name,
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

        // The claim ran as a query. Keep the model in step, or a second SAP rejection of a resent document would
        // look unchanged (SAP_ERROR to SAP_ERROR) and leave the row stuck in POSTING.
        $document->integration_status = IntegrationStatus::Posting;
        $document->syncOriginalAttribute('integration_status');

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

            return DB::transaction(function () use ($document, $command, $approver, $data, $postingData, $warehouseCode, $response, $docEntry): SapDocument {
                $normalizedData = $postingData;
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
            if ($exception->isDefiniteRejection()) {
                $command->update(['status' => IntegrationStatus::SapError, 'last_error' => $exception->technicalMessage ?? $exception->getMessage()]);
                $document->update(['integration_status' => IntegrationStatus::SapError]);
                AuditLog::query()->create([
                    'actor_id' => $approver->id,
                    'event' => 'goods_issue.sap_post_rejected',
                    'auditable_type' => SapDocument::class,
                    'auditable_id' => $document->id,
                    'metadata' => [
                        'company_db' => $document->company_db,
                        'fpk_number' => $document->fpk_number,
                        'fpk_date' => $data['fpk_date'] ?? null,
                        'sap_error' => $exception->technicalMessage ?? $exception->getMessage(),
                    ],
                ]);
            } else {
                $command->update([
                    'status' => IntegrationStatus::PostingUnknown,
                    'last_error' => "Hasil POST Goods Issue belum pasti (HTTP {$exception->httpStatus}). Periksa SAP sebelum mencoba ulang.",
                ]);
                $document->update(['integration_status' => IntegrationStatus::PostingUnknown]);
            }

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

    /**
     * Older approved documents can have an empty account snapshot. Resolve only verified SPR-IK and BBM-OU mappings;
     * other issue types retain their existing behavior until their accounting rules are verified.
     * The command payload records the exact account used on every attempt.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withAccountCodes(array $data, string $companyDb): array
    {
        foreach ($data['lines'] ?? [] as $index => $line) {
            if (! is_array($line) || filled(data_get($line, 'issue_type.account_code'))) {
                continue;
            }

            $issueTypeCode = trim((string) data_get($line, 'issue_type.code'));

            if ($issueTypeCode === 'SPR-IK') {
                $accountCode = $this->accounts->require($companyDb, $issueTypeCode, $index + 1);
            } elseif ($issueTypeCode === 'BBM-OU') {
                $accountCode = $this->accounts->require(
                    $companyDb,
                    $issueTypeCode,
                    $index + 1,
                    (string) ($line['item_code'] ?? ''),
                    (string) ($line['warehouse_code'] ?? $data['warehouse_code'] ?? ''),
                    (string) data_get($data, 'sap_context.department_cost_center.center_code', $data['department_code'] ?? ''),
                    (string) data_get($data, 'sap_context.pool_cost_center.center_code', $data['pool_code'] ?? ''),
                    (string) data_get($line, 'vehicle_cost_center.center_code', ''),
                );
            } else {
                continue;
            }

            if ($accountCode !== null) {
                data_set($data, "lines.{$index}.issue_type.account_code", $accountCode);
            }
        }

        return $data;
    }

    /**
     * BP-12: the serials picked at submission are read from SAP again. A serial that is gone stops the POST and is never
     * replaced; the document is left as SAP_ERROR so that nothing looks sent, and an unreadable SAP only stops this attempt.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, SapItemCache>  $items
     */
    private function ensureSerialsStillAvailable(SapDocument $document, SapCommand $command, User $approver, array $data, Collection $items, string $warehouseCode): void
    {
        try {
            $this->serialSelection->ensureStillAvailable($data, $items, $warehouseCode);
        } catch (SerialSelectionStaleException $exception) {
            $command->update(['status' => IntegrationStatus::SapError, 'last_error' => $exception->getMessage()]);
            $document->update(['integration_status' => IntegrationStatus::SapError]);
            AuditLog::query()->create([
                'actor_id' => $approver->id,
                'event' => 'goods_issue.serial_selection_stale',
                'auditable_type' => SapDocument::class,
                'auditable_id' => $document->id,
                'metadata' => ['company_db' => $document->company_db, 'fpk_number' => $document->fpk_number, 'reason' => $exception->getMessage()],
            ]);

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
    private function legacyVehicleCode(array $data): ?string
    {
        $vehicleCode = trim((string) data_get($data, 'vehicle_cost_center.center_code'));

        return $vehicleCode !== '' ? $vehicleCode : null;
    }
}
