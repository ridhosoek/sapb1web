<?php

namespace App\Integration\Sap;

use InvalidArgumentException;
use RuntimeException;

class SapCompanyContext
{
    private ?string $selectedCompanyDb = null;

    /** @return list<string> */
    public function available(): array
    {
        $configured = config('sap.company_databases', []);
        $default = config('sap.company_db');
        $companies = is_array($configured) ? $configured : [];

        if (is_string($default) && trim($default) !== '') {
            $companies[] = $default;
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $companyDb): string => is_string($companyDb) ? trim($companyDb) : '', $companies),
        )));
    }

    public function select(string $companyDb): void
    {
        if (! $this->isAllowed($companyDb)) {
            throw new InvalidArgumentException('The selected SAP company database is not allowed.');
        }

        $this->selectedCompanyDb = $companyDb;
    }

    public function current(): string
    {
        if ($this->selectedCompanyDb !== null) {
            return $this->selectedCompanyDb;
        }

        $default = config('sap.company_db');

        if (is_string($default) && $this->isAllowed($default)) {
            return $default;
        }

        return $this->available()[0]
            ?? throw new RuntimeException('No SAP company database is configured.');
    }

    public function isAllowed(mixed $companyDb): bool
    {
        return is_string($companyDb) && in_array($companyDb, $this->available(), true);
    }
}
