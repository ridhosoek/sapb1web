<?php

namespace App\Application\Inventory;

use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\MasterData\WarehouseStockGateway;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use Illuminate\Support\Collection;
use Throwable;

class GoodsIssueStockService
{
    public function __construct(private readonly WarehouseStockGateway $warehouseStocks) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, SapItemCache>  $items
     */
    public function ensureAvailable(array $data, string $companyDb, string $warehouseCode, Collection $items): void
    {
        $stocks = SapItemWarehouseStock::query()
            ->forCompany($companyDb)
            ->forWarehouse($warehouseCode)
            ->where('is_locked', false)
            ->whereIn('sap_item_cache_id', $items->keys())
            ->get()
            ->keyBy('sap_item_cache_id');

        $requestedByItem = collect($data['lines'])
            ->groupBy(fn (array $line): int => (int) $line['item_id'])
            ->map(fn (Collection $lines): float => (float) $lines->sum(fn (array $line): float => (float) $line['quantity']));

        foreach ($requestedByItem as $itemId => $requestedQuantity) {
            $itemId = (int) $itemId;
            $item = $items->get($itemId);
            $stock = $stocks->get($itemId);
            $available = (float) ($stock?->available_stock ?? 0);

            if (! $item || ! $stock || $requestedQuantity > $available) {
                $itemLabel = $item?->item_code ?? "ID {$itemId}";
                $formattedAvailable = number_format($available, 2, ',', '.');

                throw new SapSubmissionException("Stok tersedia item {$itemLabel} di warehouse {$warehouseCode} hanya {$formattedAvailable}. Muat ulang form sebelum mengirim ulang.");
            }
        }
    }

    /**
     * Final stock check against live SAP data, run right before the POST (TD-02).
     *
     * The portal limit is max(0, InStock - Committed): stock committed to other documents is not issued. Any
     * unreadable, missing, locked, or invalid data stops the POST. SAP remains the final authority and can still
     * reject a request, for example after a parallel stock movement.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, SapItemCache>  $items
     */
    public function ensureLiveAvailable(array $data, string $warehouseCode, Collection $items): void
    {
        $requestedByCode = [];

        foreach (collect($data['lines'])->groupBy(fn (array $line): int => (int) $line['item_id']) as $itemId => $lines) {
            $itemCode = trim((string) $items->get((int) $itemId)?->item_code);

            if ($itemCode === '') {
                throw new SapSubmissionException("Item ID {$itemId} tidak ditemukan di portal, sehingga stok live SAP tidak dapat diperiksa. Goods Issue belum dikirim ke SAP.");
            }

            $requestedByCode[$itemCode] = ($requestedByCode[$itemCode] ?? 0.0) + (float) $lines->sum(fn (array $line): float => (float) $line['quantity']);
        }

        try {
            $live = $this->warehouseStocks->forItemsInWarehouse($warehouseCode, array_keys($requestedByCode));
        } catch (Throwable $exception) {
            report($exception);

            throw new SapSubmissionException('Stok live SAP tidak dapat dibaca, sehingga Goods Issue belum dikirim ke SAP dan tidak ada dokumen SAP yang dibuat. Coba kirim ulang setelah koneksi SAP pulih.');
        }

        foreach ($requestedByCode as $itemCode => $requested) {
            $row = $live[$itemCode] ?? null;
            $stock = $row['stock'] ?? [];

            if ($row === null || ($stock['WarehouseCode'] ?? null) !== $warehouseCode) {
                throw new SapSubmissionException("Item {$itemCode} tidak ditemukan di warehouse {$warehouseCode} pada SAP. Goods Issue belum dikirim ke SAP.");
            }

            $flags = [$row['item']['InventoryItem'] ?? null, $row['item']['Frozen'] ?? null, $stock['Locked'] ?? null];
            $isYesNo = fn (mixed $value): bool => in_array($value, ['Y', 'N', 'tYES', 'tNO'], true);

            if (! is_numeric($stock['InStock'] ?? null) || ! is_numeric($stock['Committed'] ?? null) || ! collect($flags)->every($isYesNo)) {
                throw new SapSubmissionException("Data stok SAP item {$itemCode} di warehouse {$warehouseCode} tidak valid atau tidak lengkap. Goods Issue belum dikirim ke SAP.");
            }

            $isYes = fn (mixed $value): bool => in_array($value, ['Y', 'tYES'], true);

            if (! $isYes($row['item']['InventoryItem'])) {
                throw new SapSubmissionException("Item {$itemCode} bukan item inventori di SAP. Goods Issue belum dikirim ke SAP.");
            }

            if ($isYes($row['item']['Frozen']) || $isYes($stock['Locked'])) {
                throw new SapSubmissionException("Item {$itemCode} terkunci (frozen/locked) di warehouse {$warehouseCode} pada SAP. Goods Issue belum dikirim ke SAP.");
            }

            $inStock = (float) $stock['InStock'];
            $committed = (float) $stock['Committed'];
            $available = max(0.0, $inStock - $committed);

            if (round($requested, 6) > round($available, 6)) {
                throw new SapSubmissionException(sprintf(
                    'Stok SAP item %s di warehouse %s tidak cukup: boleh dikeluarkan %s (stok %s dikurangi committed %s), diminta %s. Goods Issue belum dikirim ke SAP.',
                    $itemCode,
                    $warehouseCode,
                    $this->formatQuantity($available),
                    $this->formatQuantity($inStock),
                    $this->formatQuantity($committed),
                    $this->formatQuantity($requested),
                ));
            }
        }
    }

    private function formatQuantity(float $quantity): string
    {
        return number_format($quantity, 2, ',', '.');
    }

    /** @param array<string, mixed> $data */
    public function decrement(array $data, string $companyDb, string $warehouseCode): void
    {
        $issuedByItem = collect($data['lines'])
            ->groupBy(fn (array $line): int => (int) $line['item_id'])
            ->map(fn (Collection $lines): float => (float) $lines->sum(fn (array $line): float => (float) $line['quantity']));

        foreach ($issuedByItem as $itemId => $quantity) {
            $stock = SapItemWarehouseStock::query()
                ->forCompany($companyDb)
                ->forWarehouse($warehouseCode)
                ->where('sap_item_cache_id', (int) $itemId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                continue;
            }

            $stock->update([
                'in_stock' => max(0, (float) $stock->in_stock - $quantity),
                'available_stock' => max(0, (float) $stock->available_stock - $quantity),
                'synced_at' => now(),
            ]);
        }
    }
}
