<?php

namespace App\Application\Inventory;

use App\Domain\Sap\Enums\SerialIssueRequirement;
use App\Exceptions\SapSubmissionException;
use App\Exceptions\SerialSelectionStaleException;
use App\Integration\Sap\MasterData\SerialStockGateway;
use App\Models\SapItemCache;
use Illuminate\Support\Collection;

/**
 * BP-12 / TD-13: serial-managed items are issued from serials that the creator picks from SAP, one per unit. The browser
 * only sends SAP system numbers; every claim about an item, warehouse and availability is checked against SAP here. A
 * pick is not a reservation: the same check runs again right before the POST after approval, and a serial that is gone
 * stops the POST instead of being replaced.
 */
class GoodsIssueSerialSelection
{
    public function __construct(private readonly SerialStockGateway $serials) {}

    public function isEnabled(): bool
    {
        return (bool) config('sap.goods_issue.serial_selection.enabled');
    }

    /**
     * Resolves the serials picked on each submitted line.
     *
     * @param  array<int, array<string, mixed>>  $lines  submitted lines keyed like the form
     * @param  Collection<int, SapItemCache>  $items
     * @return array{serials: array<int, list<array<string, mixed>>>, errors: array<string, string>}
     */
    public function resolve(array $lines, Collection $items, string $warehouseCode): array
    {
        $errors = [];
        $picked = [];
        $wanted = [];

        foreach ($lines as $index => $line) {
            $item = $items->get((int) ($line['item_id'] ?? 0));
            $numbers = collect($line['serial_system_numbers'] ?? [])->map(fn (mixed $number): int => (int) $number)->values();
            $key = "lines.{$index}.serial_system_numbers";

            if (! $item) {
                continue;
            }

            switch ($item->serialIssueRequirement()) {
                case SerialIssueRequirement::NonSerial:
                    if ($numbers->isNotEmpty()) {
                        $errors[$key] = "Item {$item->item_code} tidak dikelola serial di SAP, sehingga serial tidak boleh dikirim.";
                    }

                    break;
                case SerialIssueRequirement::Undetermined:
                    $errors[$key] = "Pengelolaan serial item {$item->item_code} belum terbaca dari SAP. Pengajuan baris ini ditahan; minta tim SAP melengkapi data Item Master lalu muat ulang.";

                    break;
                case SerialIssueRequirement::SerialRequired:
                    $quantity = (float) ($line['quantity'] ?? 0);

                    if (floor($quantity) !== $quantity) {
                        $errors["lines.{$index}.quantity"] = "Qty issue item {$item->item_code} harus bilangan bulat karena dikelola serial.";
                    } elseif ($numbers->count() !== (int) $quantity) {
                        $errors[$key] = "Pilih tepat {$quantity} serial untuk {$item->item_code} (terpilih {$numbers->count()}).";
                    } elseif ($numbers->count() !== $numbers->unique()->count()) {
                        $errors[$key] = "Serial {$item->item_code} dipilih lebih dari sekali.";
                    }

                    if (isset($errors[$key]) || isset($errors["lines.{$index}.quantity"])) {
                        break;
                    }

                    foreach ($numbers as $number) {
                        if (isset($wanted[$item->id][$number])) {
                            $errors[$key] = "Serial {$item->item_code} sudah dipilih pada baris lain.";

                            break 2;
                        }

                        $wanted[$item->id][$number] = $index;
                    }

                    $picked[$index] = $numbers->all();

                    break;
            }
        }

        $serials = [];

        foreach ($picked as $index => $numbers) {
            $item = $items->get((int) $lines[$index]['item_id']);

            try {
                $found = $this->serials->findAvailable($item->item_code, $warehouseCode, $numbers);
            } catch (SapSubmissionException $exception) {
                $errors["lines.{$index}.serial_system_numbers"] = $exception->getMessage();

                continue;
            }

            $missing = collect($numbers)->reject(fn (int $number): bool => isset($found[$number]));

            if ($missing->isNotEmpty()) {
                $errors["lines.{$index}.serial_system_numbers"] = "Serial {$item->item_code} yang dipilih tidak tersedia di warehouse {$warehouseCode} pada SAP. Muat ulang daftar dan pilih serial lain.";

                continue;
            }

            $serials[$index] = collect($numbers)->map(fn (int $number): array => $found[$number])->values()->all();
        }

        return ['serials' => $serials, 'errors' => $errors];
    }

    /**
     * Reads every serial of an approved Goods Issue again, right before the POST.
     *
     * @param  array<string, mixed>  $data  normalized document data
     * @param  Collection<int, SapItemCache>  $items
     *
     * @throws SerialSelectionStaleException when a serial is gone, changed or picked twice; no POST must follow
     * @throws SapSubmissionException when SAP cannot be read; no POST must follow
     */
    public function ensureStillAvailable(array $data, Collection $items, string $warehouseCode): void
    {
        $problems = [];
        $seen = [];

        foreach ($data['lines'] ?? [] as $index => $line) {
            $snapshot = collect(data_get($line, 'serial_selection.serials', []))->keyBy('system_number');

            if ($snapshot->isEmpty()) {
                continue;
            }

            $item = $items->get((int) ($line['item_id'] ?? 0));
            $number = $index + 1;

            if (! $item || (float) ($line['quantity'] ?? 0) !== (float) $snapshot->count()) {
                $problems[] = "baris {$number}: jumlah serial tidak sesuai Qty";

                continue;
            }

            $found = $this->serials->findAvailable($item->item_code, $warehouseCode, $snapshot->keys()->all());

            foreach ($snapshot as $systemNumber => $serial) {
                $now = $found[$systemNumber] ?? null;

                if (isset($seen[$item->id][$systemNumber])) {
                    $problems[] = "baris {$number}: serial {$serial['label']} ganda";
                } elseif ($now === null) {
                    $problems[] = "baris {$number}: serial {$serial['label']} sudah tidak tersedia di {$warehouseCode}";
                } elseif ($now['internal_serial'] !== ($serial['internal_serial'] ?? null) || $now['manufacturer_serial'] !== ($serial['manufacturer_serial'] ?? null)) {
                    $problems[] = "baris {$number}: identitas serial {$serial['label']} berubah di SAP";
                }

                $seen[$item->id][$systemNumber] = true;
            }
        }

        if ($problems !== []) {
            throw new SerialSelectionStaleException('Serial yang dipilih saat pengajuan tidak lagi valid di SAP ('.implode('; ', $problems).'). Goods Issue belum dikirim ke SAP dan serial tidak diganti otomatis. Minta pembuat mengajukan Goods Issue baru dengan serial yang tersedia.');
        }
    }
}
