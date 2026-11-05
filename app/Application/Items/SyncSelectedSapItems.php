<?php

namespace App\Application\Items;

use App\Integration\Sap\MasterData\ItemGateway;
use App\Integration\Sap\MasterData\ItemGroupGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapItemCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncSelectedSapItems
{
    public function __construct(
        private readonly ItemGateway $items,
        private readonly ItemGroupGateway $itemGroups,
        private readonly SapCompanyContext $companies,
    ) {}

    /**
     * Refresh only the requested item master records. This deliberately avoids
     * a full item or item-group scan when opening a Goods Receipt PO.
     *
     * @param  list<string>  $itemCodes
     * @return Collection<string, SapItemCache>
     */
    public function handle(array $itemCodes): Collection
    {
        $codes = collect($itemCodes)
            ->filter(fn (mixed $itemCode): bool => is_string($itemCode) && trim($itemCode) !== '')
            ->map(fn (string $itemCode): string => trim($itemCode))
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        return $this->cacheMasterItems(
            collect($this->items->findByCodes($codes->all())),
            $codes,
        );
    }

    /** @return Collection<string, SapItemCache> */
    public function searchActivePurchaseItems(string $term, int $limit = 50): Collection
    {
        $masterItems = collect($this->items->searchActivePurchaseItems($term, $limit));
        $codes = $masterItems
            ->pluck('ItemCode')
            ->filter(fn (mixed $itemCode): bool => is_string($itemCode) && trim($itemCode) !== '')
            ->map(fn (string $itemCode): string => trim($itemCode))
            ->unique()
            ->values();

        return $this->cacheMasterItems($masterItems, $codes);
    }

    /**
     * @param  Collection<int, mixed>  $masterItems
     * @param  Collection<int, string>  $codes
     * @return Collection<string, SapItemCache>
     */
    private function cacheMasterItems(Collection $masterItems, Collection $codes): Collection
    {
        $masterItems = $masterItems
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['ItemCode'] ?? null))
            ->keyBy(fn (array $item): string => trim((string) $item['ItemCode']));
        $groupNumbers = $masterItems
            ->pluck('ItemsGroupCode')
            ->filter(fn (mixed $number): bool => is_numeric($number))
            ->map(fn (mixed $number): int => (int) $number)
            ->unique()
            ->values()
            ->all();
        $groupNames = [];

        if ($groupNumbers !== []) {
            try {
                $groupNames = $this->itemGroups->namesByNumbers($groupNumbers);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $companyDb = $this->companies->current();

        DB::transaction(function () use ($masterItems, $groupNames, $companyDb): void {
            foreach ($masterItems as $item) {
                $itemCode = trim((string) $item['ItemCode']);
                $itemGroupCode = is_numeric($item['ItemsGroupCode'] ?? null)
                    ? (int) $item['ItemsGroupCode']
                    : null;

                SapItemCache::query()->updateOrCreate(
                    ['company_db' => $companyDb, 'item_code' => $itemCode],
                    [
                        'item_name' => trim((string) ($item['ItemName'] ?? '')) ?: '(Tanpa nama)',
                        'inventory_uom' => filled($item['InventoryUOM'] ?? null) ? (string) $item['InventoryUOM'] : null,
                        'item_group_code' => $itemGroupCode,
                        'item_group_name' => $itemGroupCode !== null ? ($groupNames[$itemGroupCode] ?? null) : null,
                        'is_inventory_item' => ($item['InventoryItem'] ?? null) === 'tYES',
                        'is_purchase_item' => ($item['PurchaseItem'] ?? null) === 'tYES',
                        'manages_serial_numbers' => ($item['ManageSerialNumbers'] ?? null) === 'tYES',
                        'serial_management_method' => filled($item['SRIAndBatchManageMethod'] ?? null)
                            ? (string) $item['SRIAndBatchManageMethod']
                            : null,
                        'is_active' => ($item['Frozen'] ?? null) === 'tNO',
                        'source_payload' => $item,
                        'synced_at' => now(),
                    ],
                );
            }
        });

        return SapItemCache::query()
            ->forCompany($companyDb)
            ->whereIn('item_code', $codes)
            ->get()
            ->keyBy('item_code');
    }
}
