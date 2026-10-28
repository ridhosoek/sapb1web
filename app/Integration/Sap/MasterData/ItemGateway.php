<?php

namespace App\Integration\Sap\MasterData;

use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

class ItemGateway
{
    public function __construct(private readonly SapServiceLayerClient $client) {}

    /**
     * @return list<array{ItemCode: string, ItemName: string, InventoryUOM?: string|null, InventoryItem?: string, PurchaseItem?: string, Frozen?: string}>
     */
    public function activeItems(int $limit, int $skip = 0): array
    {
        $response = $this->client->request('GET', config('sap.endpoints.items'), [
            'headers' => ['Prefer' => "odata.maxpagesize={$limit}"],
            'query' => [
                '$select' => 'ItemCode,ItemName,InventoryUOM,InventoryItem,PurchaseItem,Frozen',
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
}
