<?php

namespace App\Integration\Sap\Mapping;

use App\Exceptions\SapSubmissionException;
use App\Models\SapCostCenterCache;
use Illuminate\Support\Str;

class SapCostCenterResolver
{
    /**
     * Finds the active cost center of one dimension in one SAP CompanyDB by its code. The code is the only key: a
     * similar name is never accepted as a mapping (TD-09).
     */
    public function resolve(string $companyDb, int $dimension, string $localCode, string $label): SapCostCenterCache
    {
        $normalizedCode = $this->normalize($localCode);

        $costCenter = $normalizedCode === '' ? null : SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', $dimension)
            ->where('is_active', true)
            ->get()
            ->first(fn (SapCostCenterCache $candidate): bool => $this->normalize($candidate->center_code) === $normalizedCode);

        if (! $costCenter) {
            throw new SapSubmissionException("{$label} [{$localCode}] tidak memiliki cost center aktif pada dimensi {$dimension} di database SAP {$companyDb}. Minta Admin HO memperbarui pemetaan operasional user untuk database ini.");
        }

        return $costCenter;
    }

    private function normalize(string $value): string
    {
        return Str::lower(trim($value));
    }
}
