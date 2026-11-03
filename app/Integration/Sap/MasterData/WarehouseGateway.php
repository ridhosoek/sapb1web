<?php

namespace App\Integration\Sap\MasterData;

use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

class WarehouseGateway
{
    public function __construct(private readonly SapServiceLayerClient $client) {}

    /** @return list<string> */
    public function activeWarehouseCodes(int $pageSize = 100): array
    {
        $codes = [];
        $skip = 0;

        do {
            $response = $this->client->request('GET', config('sap.endpoints.warehouses'), [
                'headers' => ['Prefer' => "odata.maxpagesize={$pageSize}"],
                'query' => [
                    '$select' => 'WarehouseCode',
                    '$filter' => "Inactive eq 'tNO'",
                    '$orderby' => 'WarehouseCode asc',
                    '$top' => $pageSize,
                    '$skip' => $skip,
                ],
            ]);
            $rows = $response->json('value');

            if (! is_array($rows)) {
                throw new UnexpectedValueException('SAP Warehouses response did not contain a value collection.');
            }

            $pageCodes = collect($rows)
                ->filter(fn (mixed $row): bool => is_array($row))
                ->pluck('WarehouseCode')
                ->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
                ->map(fn (string $code): string => trim($code))
                ->values()
                ->all();
            array_push($codes, ...$pageCodes);
            $received = count($rows);
            $skip += $received;
        } while ($received > 0);

        return array_values(array_unique($codes));
    }
}
