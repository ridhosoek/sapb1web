<?php

namespace App\Integration\Sap\MasterData;

use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\SapServiceLayerClient;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reads the serial numbers that SAP holds in stock in one warehouse (BP-12 / TD-13).
 *
 * The standard `SerialNumberDetails` entity has no warehouse or stock status, so the source is a collection that the
 * SAP team provides (`sap.goods_issue.serial_selection.entity`, contract in docs/integration-contracts.md). Every read
 * is limited to the current Service Layer session (CompanyDB), one item code and one warehouse. A row without a
 * system number, without any serial text, or without stock is never offered.
 */
class SerialStockGateway
{
    public function __construct(private readonly SapServiceLayerClient $client) {}

    public function isConfigured(): bool
    {
        return filled(config('sap.goods_issue.serial_selection.entity'));
    }

    /**
     * @return array{serials: list<array{system_number: int, abs_entry: int|null, manufacturer_serial: string|null, internal_serial: string|null, label: string}>, incomplete: int}
     */
    public function available(string $itemCode, string $warehouseCode, ?string $search = null): array
    {
        $fields = $this->fields();
        $filter = $this->scopeFilter($itemCode, $warehouseCode, $fields)." and {$fields['quantity']} gt 0";
        $rows = $this->read($filter, $fields, (int) config('sap.goods_issue.serial_selection.page_size', 50));
        $serials = collect($rows)->map(fn (array $row): ?array => $this->serial($row, $fields));
        $needle = mb_strtolower(trim((string) $search));

        return [
            'serials' => $serials->filter()
                ->filter(fn (array $serial): bool => $needle === '' || str_contains(mb_strtolower($serial['label']), $needle))
                ->values()
                ->all(),
            'incomplete' => $serials->filter(fn (?array $serial): bool => $serial === null)->count(),
        ];
    }

    /**
     * Reads the chosen serials again. A serial that is absent, without stock, or incomplete is simply not returned.
     *
     * @param  list<int>  $systemNumbers
     * @return array<int, array{system_number: int, abs_entry: int|null, manufacturer_serial: string|null, internal_serial: string|null, label: string}> keyed by system number
     */
    public function findAvailable(string $itemCode, string $warehouseCode, array $systemNumbers): array
    {
        if ($systemNumbers === []) {
            return [];
        }

        $fields = $this->fields();
        $numbers = collect($systemNumbers)->map(fn (mixed $number): int => (int) $number)->unique()->values();
        $filter = $this->scopeFilter($itemCode, $warehouseCode, $fields)
            ." and {$fields['quantity']} gt 0 and ("
            .$numbers->map(fn (int $number): string => "{$fields['system_number']} eq {$number}")->implode(' or ').')';
        $found = [];

        foreach ($this->read($filter, $fields, max($numbers->count(), 1)) as $row) {
            $serial = $this->serial($row, $fields);

            if ($serial !== null && $numbers->contains($serial['system_number'])) {
                $found[$serial['system_number']] = $serial;
            }
        }

        return $found;
    }

    /** @return array<string, string> */
    private function fields(): array
    {
        if (! $this->isConfigured()) {
            throw new SapSubmissionException('Sumber serial SAP belum disediakan oleh tim SAP, sehingga serial belum dapat dipilih. Hubungi tim SAP.');
        }

        return (array) config('sap.goods_issue.serial_selection.fields');
    }

    /** @param array<string, string> $fields */
    private function scopeFilter(string $itemCode, string $warehouseCode, array $fields): string
    {
        $item = str_replace("'", "''", $itemCode);
        $warehouse = str_replace("'", "''", $warehouseCode);

        return "{$fields['item_code']} eq '{$item}' and {$fields['warehouse_code']} eq '{$warehouse}'";
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<array<string, mixed>>
     */
    private function read(string $filter, array $fields, int $limit): array
    {
        try {
            $response = $this->client->request('GET', '/'.config('sap.goods_issue.serial_selection.entity'), [
                'headers' => ['Prefer' => "odata.maxpagesize={$limit}"],
                'query' => [
                    '$select' => implode(',', array_values($fields)),
                    '$filter' => $filter,
                    '$orderby' => "{$fields['internal_serial']} asc",
                    '$top' => $limit,
                ],
            ]);
            $rows = $response->json('value');
        } catch (Throwable $exception) {
            report($exception);

            throw new SapSubmissionException('Serial dari SAP tidak dapat dibaca saat ini. Coba lagi setelah koneksi SAP pulih, atau hubungi tim SAP.');
        }

        if (! is_array($rows)) {
            throw new SapSubmissionException('Serial dari SAP tidak dapat dibaca saat ini. Coba lagi setelah koneksi SAP pulih, atau hubungi tim SAP.');
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $fields
     * @return array{system_number: int, abs_entry: int|null, manufacturer_serial: string|null, internal_serial: string|null, label: string}|null
     */
    private function serial(array $row, array $fields): ?array
    {
        $systemNumber = $row[$fields['system_number']] ?? null;
        $manufacturer = trim((string) ($row[$fields['manufacturer_serial']] ?? ''));
        $internal = trim((string) ($row[$fields['internal_serial']] ?? ''));
        $quantity = $row[$fields['quantity']] ?? null;

        if (! is_numeric($systemNumber) || ! is_numeric($quantity) || (float) $quantity <= 0 || ($manufacturer === '' && $internal === '')) {
            return null;
        }

        $absEntry = $row[$fields['abs_entry']] ?? null;

        return [
            'system_number' => (int) $systemNumber,
            'abs_entry' => is_numeric($absEntry) ? (int) $absEntry : null,
            'manufacturer_serial' => $manufacturer !== '' ? $manufacturer : null,
            'internal_serial' => $internal !== '' ? $internal : null,
            'label' => collect([$internal, $manufacturer])->filter()->unique()->implode(' / '),
        ];
    }
}
