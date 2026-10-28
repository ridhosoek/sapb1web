<?php

namespace App\Integration\Sap\MasterData;

use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

class WarehouseStockGateway
{
    public function __construct(private readonly SapServiceLayerClient $client) {}

    /**
     * @return list<array{
     *     item: array{ItemCode?: mixed, ItemName?: mixed, InventoryUOM?: mixed},
     *     stock: array{WarehouseCode?: mixed, InStock?: mixed, Committed?: mixed, Ordered?: mixed, MinimalStock?: mixed, Locked?: mixed}
     * }>
     */
    public function activeInventoryForWarehouse(string $warehouseCode, int $limit, int $skip = 0): array
    {
        $dollar = '$';
        $escapedWarehouseCode = str_replace("'", "''", $warehouseCode);
        $response = $this->client->request('GET', "/{$dollar}crossjoin(Items,Items/ItemWarehouseInfoCollection)", [
            'headers' => ['Prefer' => "odata.maxpagesize={$limit}"],
            'query' => [
                "{$dollar}expand" => "Items({$dollar}select=ItemCode,ItemName,InventoryUOM),Items/ItemWarehouseInfoCollection({$dollar}select=WarehouseCode,InStock,Committed,Ordered,MinimalStock,Locked)",
                "{$dollar}filter" => "Items/ItemCode eq Items/ItemWarehouseInfoCollection/ItemCode and Items/ItemWarehouseInfoCollection/WarehouseCode eq '{$escapedWarehouseCode}' and Items/Frozen eq 'tNO' and Items/InventoryItem eq 'tYES' and Items/ItemWarehouseInfoCollection/Locked eq 'tNO'",
                "{$dollar}orderby" => 'Items/ItemCode asc',
                "{$dollar}top" => $limit,
                "{$dollar}skip" => $skip,
            ],
        ]);

        $rows = $response->json('value');

        if (! is_array($rows)) {
            throw new UnexpectedValueException('SAP warehouse stock response did not contain a value collection.');
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'item' => is_array($row['Items'] ?? null) ? $row['Items'] : [],
                'stock' => is_array($row['Items/ItemWarehouseInfoCollection'] ?? null)
                    ? $row['Items/ItemWarehouseInfoCollection']
                    : [],
            ])
            ->values()
            ->all();
    }
}
