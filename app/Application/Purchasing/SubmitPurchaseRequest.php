<?php

namespace App\Application\Purchasing;

use App\Application\Approvals\StartDocumentApproval;
use App\Application\Users\OperationalMapping;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
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
        private readonly OperationalMapping $operationalMapping,
        private readonly StartDocumentApproval $startApproval,
        private readonly PurchaseRequestUsageHistory $usageHistory,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, User $user, ?string $ipAddress = null): SapDocument
    {
        $companyDb = $this->companies->current();
        $existing = SapCommand::query()->where('transaction_uuid', $data['transaction_uuid'])->first();

        if ($existing) {
            if ($existing->company_db === $companyDb) {
                return SapDocument::query()->where('transaction_uuid', $data['transaction_uuid'])->firstOrFail();
            }

            throw new SapSubmissionException('Transaksi ini sudah pernah diproses untuk database lain.');
        }

        $operational = $this->operationalMapping->resolve($user, $companyDb);
        $profile = $operational->profile;
        $departmentCostCenter = $operational->departmentCostCenter;
        $poolCostCenter = $operational->poolCostCenter;
        $vehicleCostCenters = SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', config('sap.dimensions.vehicle'))
            ->where('is_active', true)
            ->whereIn('id', collect($data['lines'])->pluck('vehicle_cost_center_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $items = SapItemCache::query()
            ->forCompany($companyDb)
            ->whereIn('id', collect($data['lines'])->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $usageHistories = collect();
        $historyWarnings = [];
        collect($data['lines'])
            ->filter(fn (array $line): bool => filled($line['vehicle_cost_center_id'] ?? null))
            ->groupBy(fn (array $line): int => (int) $line['vehicle_cost_center_id'])
            ->each(function ($lines, int $vehicleCostCenterId) use ($vehicleCostCenters, $items, $operational, $companyDb, $usageHistories, &$historyWarnings): void {
                $vehicleCostCenter = $vehicleCostCenters->get($vehicleCostCenterId);

                if (! $vehicleCostCenter) {
                    throw new SapSubmissionException('Cost center Nopol pada detail Purchase Request tidak ditemukan pada cache SAP aktif.');
                }

                $lineItems = $lines
                    ->pluck('item_id')
                    ->map(fn (mixed $itemId): ?SapItemCache => $items->get((int) $itemId))
                    ->filter()
                    ->unique('id')
                    ->values();
                $history = $this->usageHistory->capture(
                    $lineItems,
                    $vehicleCostCenter->center_code,
                    $operational->warehouseCode(),
                    $companyDb,
                );
                $historyWarnings = [...$historyWarnings, ...$history['warnings']];

                foreach ($history['histories'] as $usageHistory) {
                    $usageHistories->put(
                        $vehicleCostCenterId.':'.(int) $usageHistory['item_id'],
                        $usageHistory,
                    );
                }
            });
        $normalizedLines = collect($data['lines'])->map(function (array $line) use ($items, $vehicleCostCenters, $usageHistories): array {
            $item = $items->get((int) $line['item_id']);

            if (! $item) {
                throw new SapSubmissionException('Item Purchase Request tidak ditemukan pada cache SAP aktif.');
            }

            $vehicleCostCenterId = filled($line['vehicle_cost_center_id'] ?? null)
                ? (int) $line['vehicle_cost_center_id']
                : null;
            $vehicleCostCenter = $vehicleCostCenterId === null
                ? null
                : $vehicleCostCenters->get($vehicleCostCenterId);

            return [
                ...$line,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'inventory_uom' => $item->inventory_uom,
                'vehicle_cost_center' => $vehicleCostCenter?->only(['id', 'center_code', 'center_name', 'dimension']),
                'usage_history' => $vehicleCostCenterId === null
                    ? null
                    : $usageHistories->get($vehicleCostCenterId.':'.$item->id),
            ];
        })->values()->all();

        return DB::transaction(function () use ($companyDb, $data, $historyWarnings, $normalizedLines, $user, $operational, $profile, $departmentCostCenter, $poolCostCenter, $ipAddress): SapDocument {
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
                'pool_id' => $profile->pool_id,
                'department_id' => $profile->department_id,
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
                    'warehouse_code' => $operational->warehouseCode(),
                    'operational_snapshot' => $operational->snapshot(),
                    'requester_email' => $user->email,
                    'created_by_web_user' => $user->name,
                    'sap_context' => [
                        'department_cost_center' => $departmentCostCenter->only(['id', 'center_code']),
                        'pool_cost_center' => $poolCostCenter->only(['id', 'center_code']),
                    ],
                    'approval_status' => 'PENDING',
                    'progress_status' => 'PENDING_APPROVAL',
                    'usage_history_warnings' => array_values(array_unique($historyWarnings)),
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
                    'warehouse_code' => $operational->warehouseCode(),
                ],
                'ip_address' => $ipAddress,
            ]);

            return $document->load('approval.steps');
        });
    }
}
