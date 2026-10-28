<?php

namespace App\Integration\Sap\Documents;

use App\Integration\Sap\SapServiceLayerClient;
use UnexpectedValueException;

abstract class SapDocumentGateway
{
    public function __construct(protected readonly SapServiceLayerClient $client) {}

    abstract protected function endpointKey(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        return $this->client->request('POST', config('sap.endpoints.'.$this->endpointKey()), ['json' => $payload])->json();
    }

    /** @return array<string, mixed> */
    public function find(int $docEntry): array
    {
        $endpoint = config('sap.endpoints.'.$this->endpointKey())."({$docEntry})";

        return $this->client->request('GET', $endpoint)->json();
    }

    /**
     * @return list<array{document: array<string, mixed>, lines: list<array<string, mixed>>}>
     */
    protected function findByBaseDocument(int $baseType, int $baseDocEntry): array
    {
        if ($baseType < 1 || $baseDocEntry < 1) {
            throw new UnexpectedValueException('SAP base document type and DocEntry must be positive integers.');
        }

        $endpoint = config('sap.endpoints.'.$this->endpointKey());

        if (! is_string($endpoint) || trim($endpoint, '/') === '') {
            throw new UnexpectedValueException('SAP document endpoint is not configured.');
        }

        $dollar = '$';
        $entity = trim($endpoint, '/');
        $lineEntity = "{$entity}/DocumentLines";
        $response = $this->client->request('GET', "/{$dollar}crossjoin({$entity},{$lineEntity})", [
            'headers' => ['Prefer' => 'odata.maxpagesize=100'],
            'query' => [
                "{$dollar}expand" => "{$entity}({$dollar}select=DocEntry,DocNum,DocDate,DocDueDate,DocumentStatus,Cancelled,CardCode,CardName,Comments),{$lineEntity}({$dollar}select=DocEntry,LineNum,BaseType,BaseEntry,BaseLine,ItemCode,ItemDescription,Quantity,WarehouseCode)",
                "{$dollar}filter" => "{$entity}/DocEntry eq {$lineEntity}/DocEntry and {$lineEntity}/BaseType eq {$baseType} and {$lineEntity}/BaseEntry eq {$baseDocEntry}",
                "{$dollar}orderby" => "{$entity}/DocEntry asc,{$lineEntity}/LineNum asc",
                "{$dollar}top" => 100,
            ],
        ]);
        $rows = $response->json('value');

        if (! is_array($rows)) {
            throw new UnexpectedValueException("SAP {$entity} response did not contain a value collection.");
        }

        $documents = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $document = is_array($row[$entity] ?? null) ? $row[$entity] : [];
            $line = is_array($row[$lineEntity] ?? null) ? $row[$lineEntity] : [];
            $docEntry = $document['DocEntry'] ?? null;

            if (! is_numeric($docEntry)) {
                continue;
            }

            $key = (int) $docEntry;
            $documents[$key] ??= ['document' => $document, 'lines' => []];

            if ($line !== []) {
                $documents[$key]['lines'][] = $line;
            }
        }

        return array_values($documents);
    }
}
