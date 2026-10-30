<?php

namespace App\Integration\Sap\Reports;

use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapServiceLayerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class SparepartExpenseGateway
{
    private ?bool $sqlQueriesAvailable = null;

    public function __construct(
        private readonly SapServiceLayerClient $client,
        private readonly SparepartSqlQueries $queries,
    ) {}

    /**
     * @param  list<string>  $sources
     * @return array{rows: list<array<string, mixed>>, warnings: list<string>}
     */
    public function search(
        string $dateFrom,
        string $dateTo,
        ?string $itemCode = null,
        ?string $vehicleCode = null,
        ?string $warehouseCode = null,
        int $limit = 200,
        array $sources = ['goods_issue', 'ap_invoice', 'payment_request'],
        bool $withSerials = true,
    ): array {
        $rows = collect();
        $warnings = [];
        $filterItem = filled($itemCode);
        $filterVehicle = filled($vehicleCode);
        $filterWarehouse = filled($warehouseCode);
        $parameters = [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'accountPattern' => (string) config('sap.sparepart_report.account_pattern', '5114.500%'),
        ];

        if ($filterItem) {
            $parameters['itemCode'] = (string) $itemCode;
        }

        if ($filterVehicle) {
            $parameters['nopol'] = (string) $vehicleCode;
        }

        if ($filterWarehouse) {
            $parameters['warehouseCode'] = (string) $warehouseCode;
        }

        $filterSuffix = ($filterItem ? '_I' : '').($filterVehicle ? '_N' : '').($filterWarehouse ? '_W' : '');
        $paymentParameters = [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'accountCode1' => (string) config('sap.sparepart_report.payment_account_codes.0', '5114.5001'),
            'accountCode2' => (string) config('sap.sparepart_report.payment_account_codes.1', '5114.5003'),
        ];

        if ($filterVehicle) {
            $paymentParameters['nopol'] = (string) $vehicleCode;
        }

        if ($filterWarehouse) {
            $paymentParameters['warehouseCode'] = (string) $warehouseCode;
        }

        $definitions = [
            'goods_issue' => [
                'code' => $this->variantQueryCode((string) config('sap.sparepart_report.queries.goods_issue', 'WEB_SP_GI_V2'), $filterSuffix),
                'name' => 'Web Sparepart Goods Issue',
                'sql' => $this->queries->goodsIssue($filterItem, $filterVehicle, $filterWarehouse),
                'parameters' => $parameters,
                'label' => 'Goods Issue',
            ],
            'ap_invoice' => [
                'code' => $this->variantQueryCode((string) config('sap.sparepart_report.queries.ap_invoice', 'WEB_SP_AP_V2'), $filterSuffix),
                'name' => 'Web Sparepart AP Invoice',
                'sql' => $this->queries->apInvoice($filterItem, $filterVehicle, $filterWarehouse),
                'parameters' => $parameters,
                'label' => 'A/P Invoice',
            ],
            'payment_request' => [
                'code' => $this->variantQueryCode((string) config('sap.sparepart_report.queries.payment_request', 'WEB_SP_PAY_V2'), ($filterVehicle ? '_N' : '').($filterWarehouse ? '_W' : '')),
                'name' => 'Web Sparepart Payment Request',
                'sql' => $this->queries->paymentRequest($filterVehicle, $filterWarehouse),
                'parameters' => $paymentParameters,
                'label' => 'Payment Request',
            ],
        ];

        foreach ($sources as $source) {
            if (! isset($definitions[$source])) {
                continue;
            }

            if ($source === 'payment_request' && filled($itemCode)) {
                continue;
            }

            $definition = $definitions[$source];

            try {
                if ($this->sqlQueriesAvailable === false) {
                    $sourceRows = $this->odataSourceRows($source, $dateFrom, $dateTo, $itemCode, $vehicleCode, $warehouseCode, $limit);
                } else {
                    try {
                        $sourceRows = $this->execute(
                            code: $definition['code'],
                            name: $definition['name'],
                            sql: $definition['sql'],
                            parameters: $definition['parameters'],
                            limit: $limit,
                        );
                        $this->sqlQueriesAvailable = true;
                    } catch (SapBusinessException $exception) {
                        if (! $this->sqlQueriesUnavailable($exception)) {
                            throw $exception;
                        }

                        $this->sqlQueriesAvailable = false;
                        $sourceRows = $this->odataSourceRows($source, $dateFrom, $dateTo, $itemCode, $vehicleCode, $warehouseCode, $limit);
                    }
                }

                $rows = $rows->concat($this->normalizeRows($sourceRows, $definition['label']));
            } catch (ConnectionException $exception) {
                throw $exception;
            } catch (SapBusinessException $exception) {
                if (($exception->httpStatus ?? 0) >= 500) {
                    throw $exception;
                }

                report($exception);
                $warnings[] = "Sumber {$definition['label']} belum dapat dibaca dari database SAP ini.";
            } catch (Throwable $exception) {
                report($exception);
                $warnings[] = "Sumber {$definition['label']} belum dapat dibaca dari database SAP ini.";
            }
        }

        if ($withSerials && in_array('goods_issue', $sources, true) && $rows->contains('transaction', 'Goods Issue')) {
            try {
                if ($this->sqlQueriesAvailable === false) {
                    $rows = $this->attachOdataSerialNumbers($rows);
                } else {
                    $serialRows = $this->serialRowsForDocuments($rows);
                    $rows = $this->attachSerialNumbers($rows, $serialRows);
                }
            } catch (ConnectionException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);
                $warnings[] = 'Nomor seri ban belum dapat dibaca; transaksi lainnya tetap ditampilkan.';
            }
        }

        return [
            'rows' => $rows
                ->sortByDesc(fn (array $row): string => $row['date'].'|'.str_pad((string) $row['document_number'], 20, '0', STR_PAD_LEFT))
                ->take($limit)
                ->values()
                ->all(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  list<string>  $itemCodes
     * @return array{rows: list<array<string, mixed>>, warnings: list<string>}
     */
    public function latestGoodsIssues(
        string $dateFrom,
        string $dateTo,
        array $itemCodes,
        string $vehicleCode,
        string $warehouseCode,
    ): array {
        $itemCodes = collect($itemCodes)
            ->map(fn (mixed $itemCode): string => trim((string) $itemCode))
            ->filter()
            ->unique()
            ->values();

        if ($itemCodes->isEmpty()) {
            return ['rows' => [], 'warnings' => []];
        }

        $batchSize = max(1, min((int) config('sap.sparepart_report.query_batch_size', 10), 20));
        $rows = collect();
        $warnings = [];

        foreach ($itemCodes->chunk($batchSize) as $itemCodeBatch) {
            try {
                if ($this->sqlQueriesAvailable === false) {
                    $batchRows = $this->odataLatestGoodsIssueRows(
                        $dateFrom,
                        $dateTo,
                        $itemCodeBatch->all(),
                        $vehicleCode,
                        $warehouseCode,
                    );
                } else {
                    try {
                        $batchRows = $this->execute(
                            code: (string) config('sap.sparepart_report.queries.latest_goods_issue', 'WEB_SP_GI_LAST_V2'),
                            name: 'Web Latest Sparepart Goods Issue',
                            sql: $this->queries->latestGoodsIssues($batchSize),
                            parameters: [
                                'dateFrom' => $dateFrom,
                                'dateTo' => $dateTo,
                                'nopol' => $vehicleCode,
                                'warehouseCode' => $warehouseCode,
                                'accountPattern' => (string) config('sap.sparepart_report.account_pattern', '5114.500%'),
                                ...$this->batchParameters('itemCode', $itemCodeBatch->all(), $batchSize, '__UNUSED_ITEM__'),
                            ],
                            limit: $batchSize,
                        );
                        $this->sqlQueriesAvailable = true;
                    } catch (SapBusinessException $exception) {
                        if (! $this->sqlQueriesUnavailable($exception)) {
                            throw $exception;
                        }

                        $this->sqlQueriesAvailable = false;
                        $batchRows = $this->odataLatestGoodsIssueRows(
                            $dateFrom,
                            $dateTo,
                            $itemCodeBatch->all(),
                            $vehicleCode,
                            $warehouseCode,
                        );
                    }
                }

                $rows = $rows->concat($this->normalizeRows($batchRows, 'Goods Issue'));
            } catch (ConnectionException $exception) {
                throw $exception;
            } catch (SapBusinessException $exception) {
                if (($exception->httpStatus ?? 0) >= 500) {
                    throw $exception;
                }

                report($exception);
                $warnings[] = 'Histori Goods Issue belum dapat dibaca dari database SAP ini.';
            } catch (Throwable $exception) {
                report($exception);
                $warnings[] = 'Histori Goods Issue belum dapat dibaca dari database SAP ini.';
            }
        }

        return [
            'rows' => $rows
                ->sortByDesc(fn (array $row): string => $row['date'].'|'.str_pad((string) $row['document_number'], 20, '0', STR_PAD_LEFT))
                ->unique('item_code')
                ->values()
                ->all(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return list<array<string, mixed>>
     */
    private function execute(string $code, string $name, string $sql, array $parameters, int $limit): array
    {
        $this->assertValidQueryCode($code);
        $endpoint = $this->sqlQueryEndpoint("/SQLQueries('{$code}')/List");

        try {
            $response = $this->client->request('POST', $endpoint, [
                'query' => ['$top' => max(1, min($limit, 1000))],
                'json' => ['ParamList' => $this->parameterList($parameters)],
            ]);
        } catch (SapBusinessException $exception) {
            if (! $this->queryIsMissing($exception)) {
                throw $exception;
            }

            $this->client->request('POST', $this->sqlQueryEndpoint('/SQLQueries'), [
                'json' => [
                    'SqlCode' => $code,
                    'SqlName' => $name,
                    'SqlText' => $sql,
                ],
            ]);
            $response = $this->client->request('POST', $endpoint, [
                'query' => ['$top' => max(1, min($limit, 1000))],
                'json' => ['ParamList' => $this->parameterList($parameters)],
            ]);
        }

        $rows = $response->json('value');

        if (! is_array($rows)) {
            throw new UnexpectedValueException("SAP SQL Query {$code} did not return a value collection.");
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, string> $parameters */
    private function parameterList(array $parameters): string
    {
        return collect($parameters)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode('&');
    }

    private function queryIsMissing(SapBusinessException $exception): bool
    {
        $technicalMessage = strtolower((string) $exception->technicalMessage);

        return $exception->httpStatus === 404
            || $exception->sapCode === '-2028'
            || str_contains($technicalMessage, 'no matching records found')
            || str_contains($technicalMessage, 'not found');
    }

    private function sqlQueriesUnavailable(SapBusinessException $exception): bool
    {
        return $exception->sapCode === '200'
            && str_contains(strtolower((string) $exception->technicalMessage), "invalid entityset 'sqlqueries'");
    }

    private function assertValidQueryCode(string $code): void
    {
        if (! preg_match('/^[A-Z0-9_]{1,20}$/', $code)) {
            throw new RuntimeException('SAP sparepart report query code is invalid.');
        }
    }

    private function variantQueryCode(string $baseCode, string $suffix): string
    {
        $code = $baseCode.$suffix;
        $this->assertValidQueryCode($code);

        return $code;
    }

    /**
     * @param  list<int|string>  $values
     * @return array<string, string>
     */
    private function batchParameters(
        string $prefix,
        array $values,
        int $batchSize,
        string $emptyValue,
    ): array {
        $values = array_values($values);
        $parameters = [];

        for ($index = 1; $index <= $batchSize; $index++) {
            $parameters[$prefix.$index] = (string) ($values[$index - 1] ?? $emptyValue);
        }

        return $parameters;
    }

    private function sqlQueryEndpoint(string $path): string
    {
        $configuredUrl = config('sap.sparepart_report.service_layer_url');
        $baseUrl = is_string($configuredUrl) && $configuredUrl !== ''
            ? $configuredUrl
            : preg_replace('#/v2/?$#i', '/v1', (string) config('sap.base_url'));

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('SAP SQL Query Service Layer URL is not configured.');
        }

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    /** @return list<array<string, mixed>> */
    private function odataSourceRows(
        string $source,
        string $dateFrom,
        string $dateTo,
        ?string $itemCode,
        ?string $vehicleCode,
        ?string $warehouseCode,
        int $limit,
    ): array {
        return match ($source) {
            'goods_issue' => $this->odataDocumentRows('InventoryGenExits', 'Goods Issue', $dateFrom, $dateTo, $itemCode, $vehicleCode, $warehouseCode, $limit),
            'ap_invoice' => $this->odataDocumentRows('PurchaseInvoices', 'A/P Invoice', $dateFrom, $dateTo, $itemCode, $vehicleCode, $warehouseCode, $limit),
            'payment_request' => $this->odataPaymentRequestRows($dateFrom, $dateTo, $vehicleCode, $warehouseCode, $limit),
            default => [],
        };
    }

    /**
     * @param  list<string>  $itemCodes
     * @return list<array<string, mixed>>
     */
    private function odataLatestGoodsIssueRows(
        string $dateFrom,
        string $dateTo,
        array $itemCodes,
        string $vehicleCode,
        string $warehouseCode,
    ): array {
        return collect($itemCodes)
            ->flatMap(fn (string $itemCode): array => $this->odataDocumentRows(
                'InventoryGenExits',
                'Goods Issue',
                $dateFrom,
                $dateTo,
                $itemCode,
                $vehicleCode,
                $warehouseCode,
                1,
            ))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function odataDocumentRows(
        string $entity,
        string $transaction,
        string $dateFrom,
        string $dateTo,
        ?string $itemCode,
        ?string $vehicleCode,
        ?string $warehouseCode,
        int $limit,
    ): array {
        $dollar = '$';
        $lineEntity = "{$entity}/DocumentLines";
        $filters = [
            "{$entity}/DocEntry eq {$lineEntity}/DocEntry",
            "{$entity}/DocDate ge datetime'{$dateFrom}T00:00:00'",
            "{$entity}/DocDate le datetime'{$dateTo}T23:59:59'",
        ];
        $accountNeedle = trim((string) config('sap.sparepart_report.account_pattern', '5114.500%'), '%');

        if ($accountNeedle !== '') {
            $filters[] = "contains({$lineEntity}/AccountCode,'{$this->escapeOdata($accountNeedle)}')";
        }

        if (filled($itemCode)) {
            $filters[] = "{$lineEntity}/ItemCode eq '{$this->escapeOdata((string) $itemCode)}'";
        }

        if (filled($vehicleCode)) {
            $filters[] = "{$lineEntity}/CostingCode3 eq '{$this->escapeOdata((string) $vehicleCode)}'";
        }

        if (filled($warehouseCode)) {
            $filters[] = "{$lineEntity}/WarehouseCode eq '{$this->escapeOdata((string) $warehouseCode)}'";
        }

        $response = $this->client->request('GET', "/{$dollar}crossjoin({$entity},{$lineEntity})", [
            'query' => [
                "{$dollar}expand" => "{$entity}({$dollar}select=DocEntry,DocNum,DocDate,Cancelled,Reference2,CardCode,CardName,TransNum),{$lineEntity}({$dollar}select=LineNum,ItemCode,ItemDescription,Quantity,WarehouseCode,CostingCode3,AccountCode,StockInmPrice,UnitPrice,LineTotal,U_KM0,U_KM1)",
                "{$dollar}filter" => implode(' and ', $filters),
                "{$dollar}orderby" => "{$entity}/DocDate desc,{$entity}/DocEntry desc",
                "{$dollar}top" => max(1, min($limit, 1000)),
            ],
        ]);

        $rows = $response->json('value');

        if (! is_array($rows)) {
            throw new UnexpectedValueException("SAP {$transaction} report response did not return a value collection.");
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row) use ($entity, $lineEntity, $transaction): ?array {
                $document = is_array($row[$entity] ?? null) ? $row[$entity] : [];
                $line = is_array($row[$lineEntity] ?? null) ? $row[$lineEntity] : [];

                if (in_array(strtoupper((string) ($document['Cancelled'] ?? '')), ['Y', 'C', 'TYES'], true)) {
                    return null;
                }

                $unitPrice = $transaction === 'Goods Issue'
                    ? (float) ($line['StockInmPrice'] ?? 0)
                    : (float) ($line['UnitPrice'] ?? 0);
                $quantity = (float) ($line['Quantity'] ?? 0);
                $lineTotal = (float) ($line['LineTotal'] ?? 0);

                return [
                    'RefDate' => $document['DocDate'] ?? null,
                    'FPK' => $document['Reference2'] ?? null,
                    'ItemCode' => $line['ItemCode'] ?? null,
                    'Description' => $line['ItemDescription'] ?? null,
                    'WhsCode' => $line['WarehouseCode'] ?? null,
                    'WhsName' => null,
                    'Nopol' => $line['CostingCode3'] ?? null,
                    'VendorCode' => $document['CardCode'] ?? null,
                    'VendorName' => $document['CardName'] ?? null,
                    'Quantity' => $quantity,
                    'UnitPrice' => $unitPrice,
                    'Amount' => $transaction === 'Goods Issue' || $lineTotal === 0.0 ? $quantity * $unitPrice : $lineTotal,
                    'Currency' => 'IDR',
                    'DocEntry' => $document['DocEntry'] ?? 0,
                    'DocNum' => $document['DocNum'] ?? '',
                    'Transaction' => $transaction,
                    'TransId' => $document['TransNum'] ?? 0,
                    'KmStart' => $line['U_KM0'] ?? null,
                    'KmEnd' => $line['U_KM1'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function odataPaymentRequestRows(
        string $dateFrom,
        string $dateTo,
        ?string $vehicleCode,
        ?string $warehouseCode,
        int $limit,
    ): array {
        $dollar = '$';
        $response = $this->client->request('GET', '/UDO_PAY_REQ', [
            'query' => [
                "{$dollar}filter" => "Canceled eq 'N' and U_STATUS eq 'GENERATED' and CreateDate ge '{$dateFrom}T00:00:00' and CreateDate le '{$dateTo}T23:59:59'",
                "{$dollar}orderby" => 'CreateDate desc,DocEntry desc',
                "{$dollar}top" => max(1, min($limit, 1000)),
            ],
        ]);
        $documents = $response->json('value');

        if (! is_array($documents)) {
            throw new UnexpectedValueException('SAP Payment Request report response did not return a value collection.');
        }

        $accountCodes = collect(config('sap.sparepart_report.payment_account_codes', []))
            ->map(fn (mixed $code): string => (string) $code)
            ->all();

        return collect($documents)
            ->filter(fn (mixed $document): bool => is_array($document))
            ->flatMap(function (array $document) use ($accountCodes, $vehicleCode, $warehouseCode): array {
                $lines = is_array($document['VIT_PAYMENTREQDCollection'] ?? null)
                    ? $document['VIT_PAYMENTREQDCollection']
                    : [];

                return collect($lines)
                    ->filter(fn (mixed $line): bool => is_array($line)
                        && in_array((string) ($line['U_ACCOUNTCODE'] ?? ''), $accountCodes, true)
                        && (! filled($vehicleCode) || (string) ($line['U_DEPT'] ?? '') === $vehicleCode)
                        && (! filled($warehouseCode) || (string) ($line['U_MESIN'] ?? '') === $warehouseCode))
                    ->map(fn (array $line): array => [
                        'RefDate' => $document['CreateDate'] ?? null,
                        'FPK' => $line['U_FPK'] ?? null,
                        'ItemCode' => null,
                        'Description' => $line['U_REMARK'] ?? null,
                        'WhsCode' => $line['U_MESIN'] ?? null,
                        'WhsName' => null,
                        'Nopol' => $line['U_DEPT'] ?? null,
                        'VendorCode' => $document['U_BPCODE'] ?? null,
                        'VendorName' => $document['U_BPNAME'] ?? null,
                        'Quantity' => 0,
                        'UnitPrice' => 0,
                        'Amount' => $line['U_PAYMENTAMT'] ?? 0,
                        'Currency' => 'IDR',
                        'DocEntry' => $document['DocEntry'] ?? 0,
                        'DocNum' => $document['DocNum'] ?? '',
                        'Transaction' => 'Payment Request',
                        'TransId' => 0,
                        'KmStart' => null,
                        'KmEnd' => null,
                    ])
                    ->all();
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function attachOdataSerialNumbers(Collection $rows): Collection
    {
        $documentEntries = $rows
            ->where('transaction', 'Goods Issue')
            ->pluck('document_entry')
            ->filter()
            ->unique()
            ->values();

        if ($documentEntries->isEmpty()) {
            return $rows;
        }

        $serialRows = collect();

        foreach ($documentEntries->chunk(20) as $entries) {
            $dollar = '$';
            $filter = $entries->map(fn (mixed $entry): string => 'DocEntry eq '.(int) $entry)->implode(' or ');
            $response = $this->client->request('GET', '/InventoryGenExits', [
                'query' => [
                    "{$dollar}filter" => $filter,
                    "{$dollar}top" => $entries->count(),
                ],
            ]);

            foreach ($response->json('value', []) as $document) {
                if (! is_array($document)) {
                    continue;
                }

                foreach ($document['DocumentLines'] ?? [] as $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    foreach ($line['SerialNumbers'] ?? [] as $serial) {
                        if (! is_array($serial)) {
                            continue;
                        }

                        $serialNumber = $serial['InternalSerialNumber']
                            ?? $serial['ManufacturerSerialNumber']
                            ?? null;

                        if (filled($serialNumber)) {
                            $serialRows->push([
                                'DocEntry' => $document['DocEntry'] ?? 0,
                                'ItemCode' => $line['ItemCode'] ?? '',
                                'DistNumber' => $serialNumber,
                            ]);
                        }
                    }
                }
            }
        }

        return $this->attachSerialNumbers($rows, $serialRows->all());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function serialRowsForDocuments(Collection $rows): array
    {
        $documentEntries = $rows
            ->where('transaction', 'Goods Issue')
            ->pluck('document_entry')
            ->map(fn (mixed $documentEntry): int => (int) $documentEntry)
            ->filter(fn (int $documentEntry): bool => $documentEntry > 0)
            ->unique()
            ->values();

        if ($documentEntries->isEmpty()) {
            return [];
        }

        $batchSize = max(1, min((int) config('sap.sparepart_report.serial_batch_size', 10), 20));
        $serialRows = collect();

        foreach ($documentEntries->chunk($batchSize) as $documentEntryBatch) {
            $serialRows = $serialRows->concat($this->execute(
                code: (string) config('sap.sparepart_report.queries.serial_numbers', 'WEB_SP_SN_V2'),
                name: 'Web Sparepart Serial Numbers',
                sql: $this->queries->serialNumbers($batchSize),
                parameters: $this->batchParameters(
                    'docEntry',
                    $documentEntryBatch->all(),
                    $batchSize,
                    '-1',
                ),
                limit: min(1000, $batchSize * 100),
            ));
        }

        return $serialRows->values()->all();
    }

    private function escapeOdata(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows, string $fallbackTransaction): Collection
    {
        return collect($rows)->map(function (array $row) use ($fallbackTransaction): array {
            $quantity = (float) ($row['Quantity'] ?? 0);
            $unitPrice = (float) ($row['UnitPrice'] ?? 0);
            $amount = array_key_exists('Amount', $row) ? (float) $row['Amount'] : $quantity * $unitPrice;

            return [
                'date' => $this->normalizeDate($row['RefDate'] ?? $row['CreateDate'] ?? null),
                'fpk' => filled($row['FPK'] ?? null) ? (string) $row['FPK'] : null,
                'item_code' => filled($row['ItemCode'] ?? null) ? (string) $row['ItemCode'] : null,
                'description' => (string) ($row['Description'] ?? $row['Dscription'] ?? ''),
                'warehouse_code' => filled($row['WhsCode'] ?? null) ? (string) $row['WhsCode'] : null,
                'warehouse_name' => filled($row['WhsName'] ?? null) ? (string) $row['WhsName'] : null,
                'vehicle' => filled($row['Nopol'] ?? null) ? (string) $row['Nopol'] : null,
                'vendor_code' => filled($row['VendorCode'] ?? null) ? (string) $row['VendorCode'] : null,
                'vendor_name' => filled($row['VendorName'] ?? null) ? (string) $row['VendorName'] : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'currency' => filled($row['Currency'] ?? null) ? (string) $row['Currency'] : 'IDR',
                'document_entry' => (int) ($row['DocEntry'] ?? 0),
                'document_number' => (string) ($row['DocNum'] ?? ''),
                'transaction' => (string) ($row['Transaction'] ?? $fallbackTransaction),
                'transaction_id' => (int) ($row['TransId'] ?? 0),
                'km_start' => is_numeric($row['KmStart'] ?? null) ? (float) $row['KmStart'] : null,
                'km_end' => is_numeric($row['KmEnd'] ?? null) ? (float) $row['KmEnd'] : null,
                'serial_numbers' => [],
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $serialRows
     * @return Collection<int, array<string, mixed>>
     */
    private function attachSerialNumbers(Collection $rows, array $serialRows): Collection
    {
        $serials = collect($serialRows)
            ->filter(fn (array $row): bool => filled($row['DistNumber'] ?? null))
            ->groupBy(fn (array $row): string => ($row['DocEntry'] ?? '').'|'.($row['ItemCode'] ?? ''))
            ->map(fn (Collection $group): array => $group->pluck('DistNumber')->map(fn (mixed $serial): string => (string) $serial)->unique()->values()->all());

        return $rows->map(function (array $row) use ($serials): array {
            if ($row['transaction'] !== 'Goods Issue') {
                return $row;
            }

            $row['serial_numbers'] = $serials->get($row['document_entry'].'|'.$row['item_code'], []);

            return $row;
        });
    }

    private function normalizeDate(mixed $value): string
    {
        $date = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date, $matches)) {
            return $matches[0];
        }

        if (preg_match('/^\d{8}$/', $date)) {
            return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
        }

        return $date;
    }
}
