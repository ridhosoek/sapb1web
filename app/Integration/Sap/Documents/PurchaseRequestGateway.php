<?php

namespace App\Integration\Sap\Documents;

use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

class PurchaseRequestGateway extends SapDocumentGateway
{
    private const PAGE_SIZE = 100;

    private const MAXIMUM_PAGES = 10;

    /**
     * Purchase Requests carrying the request UUID in the given field. At most two rows are asked for, which is enough
     * to tell none, one and more than one apart. A row that cannot be verified rejects the whole answer, and an empty
     * list never proves that a POST did not happen.
     *
     * @return list<array<string, mixed>>
     */
    public function findByTransactionUuid(string $transactionUuid, string $field): array
    {
        if (! Str::isUuid($transactionUuid)) {
            throw new InvalidArgumentException('Purchase Request lookup requires a valid UUID.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
            throw new InvalidArgumentException('SAP transaction field name is not valid.');
        }

        $dollar = '$';
        $response = $this->client->request('GET', config('sap.endpoints.'.$this->endpointKey()), [
            'query' => [
                "{$dollar}select" => "DocEntry,DocNum,{$field}",
                "{$dollar}filter" => "{$field} eq '{$transactionUuid}'",
                "{$dollar}top" => 2,
            ],
        ], $transactionUuid);
        $body = $response->json();
        $rows = is_array($body) ? ($body['value'] ?? null) : null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new UnexpectedValueException('SAP Purchase Request response did not contain a value collection.');
        }

        foreach ($rows as $row) {
            $docEntry = is_array($row) ? filter_var($row['DocEntry'] ?? null, FILTER_VALIDATE_INT) : false;

            if ($docEntry === false || $docEntry < 1 || strcasecmp((string) ($row[$field] ?? ''), $transactionUuid) !== 0) {
                throw new UnexpectedValueException('SAP Purchase Request response contained a row that does not match the lookup.');
            }
        }

        if (count($rows) < 2 && (isset($body['@odata.nextLink']) || isset($body['odata.nextLink']))) {
            throw new UnexpectedValueException('SAP Purchase Request response was paged, so the number of matches is unknown.');
        }

        return $rows;
    }

    /**
     * Every Purchase Request SAP created between two dates, with its lines, for the manual link. An answer that cannot
     * be read completely is rejected, because a missing row could hide a second candidate.
     *
     * @return list<array<string, mixed>>
     */
    public function findCreatedBetween(string $from, string $to, ?string $correlationId = null): array
    {
        foreach ([$from, $to] as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException('Purchase Request search requires dates as Y-m-d.');
            }
        }

        $dollar = '$';
        $documents = [];

        for ($page = 0; $page < self::MAXIMUM_PAGES; $page++) {
            $response = $this->client->request('GET', config('sap.endpoints.'.$this->endpointKey()), [
                'headers' => ['Prefer' => 'odata.maxpagesize='.self::PAGE_SIZE],
                'query' => [
                    "{$dollar}select" => 'DocEntry,DocNum,DocDate,CreationDate,DocTime,Comments,Cancelled,DocumentStatus,DocumentLines',
                    "{$dollar}filter" => "CreationDate ge '{$from}' and CreationDate le '{$to}'",
                    "{$dollar}orderby" => 'DocEntry asc',
                    "{$dollar}skip" => count($documents),
                ],
            ], $correlationId);
            $body = $response->json();
            $rows = is_array($body) ? ($body['value'] ?? null) : null;

            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new UnexpectedValueException('SAP Purchase Request response did not contain a value collection.');
            }

            foreach ($rows as $row) {
                if (! is_array($row) || filter_var($row['DocEntry'] ?? null, FILTER_VALIDATE_INT) === false || ! is_array($row['DocumentLines'] ?? null)) {
                    throw new UnexpectedValueException('SAP Purchase Request response contained an incomplete row.');
                }

                $documents[] = $row;
            }

            $hasMore = isset($body['@odata.nextLink']) || isset($body['odata.nextLink']) || count($rows) === self::PAGE_SIZE;

            if (! $hasMore) {
                return $documents;
            }

            if ($rows === []) {
                break;
            }
        }

        throw new UnexpectedValueException('SAP Purchase Request search did not finish within the page limit.');
    }

    protected function endpointKey(): string
    {
        return 'purchase_requests';
    }
}
