<?php

namespace App\Application\Purchasing;

use App\Application\Approvals\StartDocumentApproval;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Mapping\SapCostCenterResolver;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubmitPurchaseRequest
{
    public function __construct(
        private readonly SapCompanyContext $companies,
        private readonly SapCostCenterResolver $costCenters,
        private readonly StartDocumentApproval $startApproval,
        private readonly PurchaseRequestUsageHistory $usageHistory,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, User $user, ?string $ipAddress = null): SapDocument
    {
        $user->loadMissing('operationalProfile.pool', 'operationalProfile.department');
        $companyDb = $this->companies->current();
        $existing = SapCommand::query()->where('transaction_uuid', $data['transaction_uuid'])->first();

        if ($existing) {
            if ($existing->company_db === $companyDb) {
                return SapDocument::query()->where('transaction_uuid', $data['transaction_uuid'])->firstOrFail();
            }

            throw new SapSubmissionException('Transaksi ini sudah pernah diproses untuk database lain.');
        }

        $departmentCostCenter = $this->costCenters->resolve(
            $companyDb,
            (int) config('sap.dimensions.department'),
            $user->operationalProfile->department->code,
            $user->operationalProfile->department->name,
            'Department',
        );
        $poolCostCenter = $this->costCenters->resolve(
            $companyDb,
            (int) config('sap.dimensions.pool'),
            $user->operationalProfile->pool->code,
            $user->operationalProfile->pool->name,
            'Pool',
        );
        $vehicleCostCenter = SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', config('sap.dimensions.vehicle'))
            ->where('is_active', true)
            ->findOrFail($data['vehicle_cost_center_id']);
        $items = SapItemCache::query()
            ->forCompany($companyDb)
            ->whereIn('id', collect($data['lines'])->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $history = $this->usageHistory->capture(
            $items->values(),
            $vehicleCostCenter->center_code,
            $user->operationalProfile->warehouse_code,
            $companyDb,
        );
        $usageByItemId = collect($history['histories'])->keyBy('item_id');
        $normalizedLines = collect($data['lines'])->map(function (array $line) use ($items, $usageByItemId): array {
            $item = $items->get((int) $line['item_id']);

            if (! $item) {
                throw new SapSubmissionException('Item Purchase Request tidak ditemukan pada cache SAP aktif.');
            }

            return [
                ...$line,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'inventory_uom' => $item->inventory_uom,
                'usage_history' => $usageByItemId->get($item->id),
            ];
        })->values()->all();

        return DB::transaction(function () use ($companyDb, $data, $history, $normalizedLines, $user, $departmentCostCenter, $poolCostCenter, $vehicleCostCenter, $ipAddress): SapDocument {
            SapCommand::query()->create([
                'company_db' => $companyDb,
                'transaction_uuid' => $data['transaction_uuid'],
                'command_type' => 'CREATE_PURCHASE_REQUEST',
                'sap_entity' => 'PurchaseRequests',
                'status' => IntegrationStatus::PendingPost,
                'request_payload' => null,
                'attempts' => 0,
            ]);
            $document = SapDocument::query()->create([
                'company_db' => $companyDb,
                'pool_id' => $user->operationalProfile->pool_id,
                'department_id' => $user->operationalProfile->department_id,
                'document_type' => 'PURCHASE_REQUEST',
                'sap_entity' => 'PurchaseRequests',
                'source' => 'WEB',
                'transaction_uuid' => $data['transaction_uuid'],
                'sap_business_status' => BusinessStatus::Open,
                'integration_status' => IntegrationStatus::PendingPost,
                'created_from_web' => true,
                'normalized_data' => [
                    ...$data,
                    'lines' => $normalizedLines,
                    'document_date' => now()->toDateString(),
                    'vehicle_cost_center' => $vehicleCostCenter->only(['id', 'center_code', 'center_name', 'dimension']),
                    'warehouse_code' => $user->operationalProfile->warehouse_code,
                    'requester_email' => $user->email,
                    'created_by_web_user' => $user->name,
                    'sap_context' => [
                        'department_cost_center' => $departmentCostCenter->only(['id', 'center_code']),
                        'pool_cost_center' => $poolCostCenter->only(['id', 'center_code']),
                    ],
                    'approval_status' => 'PENDING',
                    'progress_status' => 'PENDING_APPROVAL',
                    'usage_history_warnings' => $history['warnings'],
                ],
            ]);
            $this->startApproval->handle($document, $user);
            AuditLog::query()->create([
                'actor_id' => $user->id,
                'event' => 'purchase_request.submitted_for_approval',
                'auditable_type' => SapDocument::class,
                'auditable_id' => $document->id,
                'metadata' => [
                    'company_db' => $companyDb,
                    'warehouse_code' => $user->operationalProfile->warehouse_code,
                ],
                'ip_address' => $ipAddress,
            ]);

            return $document->load('approval.steps');
        });
    }
}
