<?php

namespace App\Integration\Sap\MasterData;

use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

class ItemGateway
{
    private const SELECT_FIELDS = 'ItemCode,ItemName,InventoryUOM,ItemsGroupCode,InventoryItem,PurchaseItem,ManageSerialNumbers,SRIAndBatchManageMethod,Frozen';

    public function __construct(private readonly SapServiceLayerClient $client) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function activeItems(int $limit, int $skip = 0): array
    {
        $response = $this->client->request('GET', config('sap.endpoints.items'), [
            'headers' => ['Prefer' => "odata.maxpagesize={$limit}"],
            'query' => [
                '$select' => self::SELECT_FIELDS,
                '$filter' => "Frozen eq 'tNO'",
                '$orderby' => 'ItemCode asc',
                '$top' => $limit,
                '$skip' => $skip,
            ],
        ]);

        $items = $response->json('value');

        if (! is_array($items)) {
            throw new UnexpectedValueException('SAP Items response did not contain a value collection.');
        }

        return array_values(array_filter($items, fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  list<string>  $itemCodes
     * @return list<array<string, mixed>>
     */
    public function findByCodes(array $itemCodes): array
    {
        $items = [];

        foreach (array_chunk(array_values(array_unique($itemCodes)), 20) as $codes) {
            $filter = collect($codes)
                ->map(fn (string $code): string => "ItemCode eq '".str_replace("'", "''", $code)."'")
                ->implode(' or ');
            $response = $this->client->request('GET', config('sap.endpoints.items'), [
                'headers' => ['Prefer' => 'odata.maxpagesize=20'],
                'query' => [
                    '$select' => self::SELECT_FIELDS,
                    '$filter' => $filter,
                    '$orderby' => 'ItemCode asc',
                    '$top' => 20,
                ],
            ]);
            $rows = $response->json('value');

            if (! is_array($rows)) {
                throw new UnexpectedValueException('SAP Items response did not contain a value collection.');
            }

            array_push($items, ...array_values(array_filter($rows, fn (mixed $item): bool => is_array($item))));
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchActivePurchaseItems(string $term, int $limit = 50): array
    {
        $escapedTerm = str_replace("'", "''", trim($term));
        $limit = max(1, min($limit, 50));
        $response = $this->client->request('GET', config('sap.endpoints.items'), [
            'headers' => ['Prefer' => "odata.maxpagesize={$limit}"],
            'query' => [
                '$select' => self::SELECT_FIELDS,
                '$filter' => "Frozen eq 'tNO' and PurchaseItem eq 'tYES' and (contains(ItemCode,'{$escapedTerm}') or contains(ItemName,'{$escapedTerm}'))",
                '$orderby' => 'ItemCode asc',
                '$top' => $limit,
            ],
        ]);
        $items = $response->json('value');

        if (! is_array($items)) {
            throw new UnexpectedValueException('SAP Items response did not contain a value collection.');
        }

        return array_values(array_filter($items, fn (mixed $item): bool => is_array($item)));
    }
}
