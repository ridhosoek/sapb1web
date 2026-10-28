<?php

namespace App\Integration\Sap\MasterData;

use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

class ProfitCenterGateway
{
    public function __construct(private readonly SapServiceLayerClient $client) {}

    /**
     * @return list<array{CenterCode: string, CenterName: string, InWhichDimension: int, Active: string}>
     */
    public function activeByDimension(int $dimension, int $limit, int $skip = 0): array
    {
        $response = $this->client->request('GET', config('sap.endpoints.profit_centers'), [
            'headers' => ['Prefer' => "odata.maxpagesize={$limit}"],
            'query' => [
                '$select' => 'CenterCode,CenterName,InWhichDimension,Active',
                '$filter' => "InWhichDimension eq {$dimension} and Active eq 'tYES'",
                '$orderby' => 'CenterCode asc',
                '$top' => $limit,
                '$skip' => $skip,
            ],
        ]);

        $costCenters = $response->json('value');

        if (! is_array($costCenters)) {
            throw new UnexpectedValueException('SAP ProfitCenters response did not contain a value collection.');
        }

        return array_values(array_filter($costCenters, fn (mixed $costCenter): bool => is_array($costCenter)));
    }
}
