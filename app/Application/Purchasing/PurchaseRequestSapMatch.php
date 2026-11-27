<?php

namespace App\Application\Purchasing;

use Carbon\CarbonImmutable;

/** How a Purchase Request read from SAP is compared with the request the portal sent (TD-14). */
class PurchaseRequestSapMatch
{
    /** True when SAP has closed the request, false when it is open, null when the status cannot be trusted. */
    public static function closedInSap(mixed $documentStatus): ?bool
    {
        return match ($documentStatus) {
            'bost_Open', 'O' => false,
            'bost_Close', 'C' => true,
            default => null,
        };
    }

    /** @return list<string> one entry per line (item, quantity, warehouse), independent of the line order */
    public static function lineSignature(mixed $lines): array
    {
        return collect(is_array($lines) ? $lines : [])
            ->map(fn (mixed $line): string => implode('|', [
                mb_strtoupper(trim((string) data_get($line, 'ItemCode'))),
                number_format((float) data_get($line, 'Quantity'), 4, '.', ''),
                mb_strtoupper(trim((string) data_get($line, 'WarehouseCode'))),
            ]))
            ->sort()
            ->values()
            ->all();
    }

    public static function date(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }

    /** SAP may rewrite line breaks and spacing; the words have to be the same. */
    public static function comments(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * When SAP created the document, from `CreationDate` and `DocTime` ("14:57:00", "14:57" or 1457), in the
     * application time zone. Null when either value is missing or unreadable.
     *
     * @param  array<string, mixed>  $document
     */
    public static function createdAt(array $document): ?CarbonImmutable
    {
        $date = self::date($document['CreationDate'] ?? null);
        $time = $document['DocTime'] ?? null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        if (is_int($time)) {
            $time = sprintf('%02d:%02d', intdiv($time, 100), $time % 100);
        }

        if (! is_string($time) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time, $parts) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d H:i', sprintf('%s %02d:%s', $date, (int) $parts[1], $parts[2]), config('app.timezone'));
    }
}
