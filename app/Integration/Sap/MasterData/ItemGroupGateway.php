<?php

namespace App\Integration\Sap\MasterData;

use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

class ItemGroupGateway
{
    public function __construct(private readonly SapServiceLayerClient $client) {}

    /** @return array<int, string> */
    public function namesByNumber(int $pageSize = 100): array
    {
        $groups = [];
        $skip = 0;

        do {
            $response = $this->client->request('GET', config('sap.endpoints.item_groups'), [
                'headers' => ['Prefer' => "odata.maxpagesize={$pageSize}"],
                'query' => [
                    '$select' => 'Number,GroupName',
                    '$orderby' => 'Number asc',
                    '$top' => $pageSize,
                    '$skip' => $skip,
                ],
            ]);
            $rows = $response->json('value');

            if (! is_array($rows)) {
                throw new UnexpectedValueException('SAP ItemGroups response did not contain a value collection.');
            }

            foreach ($rows as $row) {
                if (! is_array($row) || ! is_numeric($row['Number'] ?? null)) {
                    continue;
                }

                $groups[(int) $row['Number']] = trim((string) ($row['GroupName'] ?? ''));
            }

            $received = count($rows);
            $skip += $received;
        } while ($received > 0);

        return $groups;
    }

    /**
     * @param  list<int>  $numbers
     * @return array<int, string>
     */
    public function namesByNumbers(array $numbers): array
    {
        $groups = [];

        foreach (array_chunk(array_values(array_unique($numbers)), 20) as $chunk) {
            $filter = collect($chunk)
                ->map(fn (int $number): string => "Number eq {$number}")
                ->implode(' or ');
            $response = $this->client->request('GET', config('sap.endpoints.item_groups'), [
                'headers' => ['Prefer' => 'odata.maxpagesize=20'],
                'query' => [
                    '$select' => 'Number,GroupName',
                    '$filter' => $filter,
                    '$orderby' => 'Number asc',
                    '$top' => 20,
                ],
            ]);
            $rows = $response->json('value');

            if (! is_array($rows)) {
                throw new UnexpectedValueException('SAP ItemGroups response did not contain a value collection.');
            }

            foreach ($rows as $row) {
                if (! is_array($row) || ! is_numeric($row['Number'] ?? null)) {
                    continue;
                }

                $groups[(int) $row['Number']] = trim((string) ($row['GroupName'] ?? ''));
            }
        }

        return $groups;
    }
}
