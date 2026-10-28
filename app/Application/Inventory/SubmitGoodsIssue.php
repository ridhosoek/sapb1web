<?php

namespace App\Application\Inventory;

use App\Application\Approvals\StartDocumentApproval;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Mapping\SapCostCenterResolver;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\IssueType;
use App\Models\SapCommand;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubmitGoodsIssue
{
    public function __construct(
        private readonly SapCompanyContext $companies,
        private readonly SapCostCenterResolver $costCenters,
        private readonly GoodsIssueStockService $stocks,
        private readonly StartDocumentApproval $startApproval,
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
        $issueTypes = IssueType::query()
            ->whereIn('id', collect($data['lines'])->pluck('issue_type_id'))
            ->get()
            ->keyBy('id');
        $items = SapItemCache::query()
            ->forCompany($companyDb)
            ->whereIn('id', collect($data['lines'])->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $normalizedLines = collect($data['lines'])->map(function (array $line) use ($items, $issueTypes): array {
            $item = $items->get((int) $line['item_id']);
            $issueType = $issueTypes->get((int) $line['issue_type_id']);

            if (! $item) {
                throw new SapSubmissionException('Item Goods Issue tidak ditemukan pada cache SAP aktif.');
            }

            if (! $issueType) {
                throw new SapSubmissionException('Tipe pengeluaran Goods Issue tidak ditemukan atau sudah tidak aktif.');
            }

            $accountCode = trim((string) config("sap.goods_issue.account_codes.{$issueType->code}"));

            if ($accountCode === '') {
                throw new SapSubmissionException("Account Code SAP untuk tipe {$issueType->code} belum dikonfigurasi.");
            }

            return [
                ...$line,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'inventory_uom' => $item->inventory_uom,
                'issue_type' => [
                    ...$issueType->only(['id', 'code', 'name']),
                    'account_code' => $accountCode,
                ],
            ];
        })->values()->all();
        $warehouseCode = (string) $user->operationalProfile->warehouse_code;
        $this->stocks->ensureAvailable($data, $companyDb, $warehouseCode, $items);

        return DB::transaction(function () use ($companyDb, $data, $normalizedLines, $user, $vehicleCostCenter, $departmentCostCenter, $poolCostCenter, $warehouseCode, $ipAddress): SapDocument {
            SapCommand::query()->create([
                'company_db' => $companyDb,
                'transaction_uuid' => $data['transaction_uuid'],
                'command_type' => 'CREATE_GOODS_ISSUE',
                'sap_entity' => 'InventoryGenExits',
                'status' => IntegrationStatus::PendingPost,
                'request_payload' => null,
                'attempts' => 0,
            ]);
            $document = SapDocument::query()->create([
                'company_db' => $companyDb,
                'pool_id' => $user->operationalProfile->pool_id,
                'department_id' => $user->operationalProfile->department_id,
                'document_type' => 'GOODS_ISSUE',
                'sap_entity' => 'InventoryGenExits',
                'source' => 'WEB',
                'fpk_number' => $data['fpk_number'],
                'transaction_uuid' => $data['transaction_uuid'],
                'sap_business_status' => BusinessStatus::Open,
                'integration_status' => IntegrationStatus::PendingPost,
                'created_from_web' => true,
                'normalized_data' => [
                    ...$data,
                    'lines' => $normalizedLines,
                    'vehicle_cost_center' => $vehicleCostCenter->only(['id', 'center_code', 'center_name']),
                    'warehouse_code' => $warehouseCode,
                    'pool_code' => $user->operationalProfile->pool->code,
                    'department_code' => $user->operationalProfile->department->code,
                    'sap_context' => [
                        'department_cost_center' => $departmentCostCenter->only(['id', 'center_code']),
                        'pool_cost_center' => $poolCostCenter->only(['id', 'center_code']),
                    ],
                    'approval_status' => 'PENDING',
                ],
            ]);
            $this->startApproval->handle($document, $user);
            AuditLog::query()->create([
                'actor_id' => $user->id,
                'event' => 'goods_issue.submitted_for_approval',
                'auditable_type' => SapDocument::class,
                'auditable_id' => $document->id,
                'metadata' => ['company_db' => $companyDb, 'fpk_number' => $data['fpk_number']],
                'ip_address' => $ipAddress,
            ]);

            return $document->load('approval.steps');
        });
    }
}
