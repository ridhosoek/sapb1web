<?php

namespace App\Integration\Sap\MasterData;

use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\SapServiceLayerClient;

class UnitOfMeasurementGroupGateway
{
    public function __construct(private readonly SapServiceLayerClient $client) {}

    /** @return array<string, mixed> */
    public function find(int $groupEntry): array
    {
        $group = $this->client->request('GET', "/UnitOfMeasurementGroups({$groupEntry})")->json();

        if (! is_array($group) || (int) ($group['AbsEntry'] ?? 0) !== $groupEntry) {
            throw new SapSubmissionException("Grup satuan SAP {$groupEntry} tidak dapat dibaca. PR tidak dikirim.");
        }

        return $group;
    }

    public function isManual(array $group): bool
    {
        $code = mb_strtoupper(trim((string) ($group['Code'] ?? '')));
        $name = mb_strtoupper(trim((string) ($group['Name'] ?? '')));

        if ($code === '' && $name === '') {
            throw new SapSubmissionException('Nama grup satuan SAP tidak tersedia. PR tidak dikirim.');
        }

        return $code === 'MANUAL' || $name === 'MANUAL';
    }

    public function entryForCode(array $group, string $uomCode): int
    {
        $escapedCode = str_replace("'", "''", $uomCode);
        $rows = $this->client->request('GET', '/UnitOfMeasurements', [
            'query' => [
                '$select' => 'AbsEntry,Code',
                '$filter' => "Code eq '{$escapedCode}'",
                '$top' => 2,
            ],
        ])->json('value');

        if (! is_array($rows) || count($rows) !== 1 || ! is_numeric($rows[0]['AbsEntry'] ?? null)
            || ($rows[0]['Code'] ?? null) !== $uomCode) {
            throw new SapSubmissionException("Satuan {$uomCode} tidak ditemukan di SAP. PR tidak dikirim.");
        }

        $entry = (int) $rows[0]['AbsEntry'];
        $definitions = $group['UoMGroupDefinitionCollection'] ?? null;

        if (! is_array($definitions)) {
            throw new SapSubmissionException('Definisi konversi satuan SAP tidak tersedia. PR tidak dikirim.');
        }

        foreach ($definitions as $definition) {
            if (is_array($definition)
                && (int) ($definition['AlternateUoM'] ?? 0) === $entry
                && ($definition['Active'] ?? null) === 'tYES') {
                return $entry;
            }
        }

        throw new SapSubmissionException("Satuan {$uomCode} tidak aktif dalam grup item di SAP. PR tidak dikirim.");
    }
}
