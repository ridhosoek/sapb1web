<?php

namespace App\Integration\Sap\MasterData;

use App\Integration\Sap\SapCompanyContext;
use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

class GoodsIssueTypeGateway
{
    public function __construct(
        private readonly SapServiceLayerClient $client,
        private readonly SapCompanyContext $companies,
    ) {}

    /** @return array<string, array{code: string, name: string, account_code: string}> */
    public function forCompany(string $companyDb): array
    {
        $this->companies->select($companyDb);
        $types = [];

        for ($skip = 0; ;) {
            $response = $this->client->request('GET', config('sap.goods_issue.account_entity'), [
                'headers' => ['Prefer' => 'odata.maxpagesize=100'],
                'query' => [
                    '$select' => 'Code,Name,U_COA',
                    '$orderby' => 'Code asc',
                    '$top' => 100,
                    '$skip' => $skip,
                ],
            ]);
            $rows = $response->json('value');

            if (! is_array($rows)) {
                throw new UnexpectedValueException("SAP {$companyDb} tidak mengembalikan daftar tipe pengeluaran @VCGI.");
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new UnexpectedValueException("SAP {$companyDb} mengembalikan baris @VCGI yang tidak valid.");
                }

                $code = trim((string) ($row['Code'] ?? ''));
                $name = trim((string) ($row['Name'] ?? ''));
                $accountCode = trim((string) ($row['U_COA'] ?? ''));

                if ($code === '' || isset($types[$code])) {
                    throw new UnexpectedValueException("SAP {$companyDb} memiliki kode tipe pengeluaran @VCGI kosong atau duplikat.");
                }

                $types[$code] = ['code' => $code, 'name' => $name, 'account_code' => $accountCode];
            }

            if ($rows === [] || (! filled($response->json('@odata.nextLink')) && count($rows) < 100)) {
                break;
            }

            $skip += count($rows);
        }

        return $types;
    }
}
