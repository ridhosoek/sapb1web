<?php

namespace App\Integration\Sap\Mapping;

use App\Exceptions\SapSubmissionException;
use App\Models\SapCostCenterCache;
use Illuminate\Support\Str;

class SapCostCenterResolver
{
    public function resolve(string $companyDb, int $dimension, string $localCode, string $localName, string $label): SapCostCenterCache
    {
        $costCenters = SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', $dimension)
            ->where('is_active', true)
            ->get();

        $normalizedCode = $this->normalize($localCode);
        $normalizedName = $this->normalize($localName);
        $costCenter = $costCenters->first(
            fn (SapCostCenterCache $candidate): bool => $this->normalize($candidate->center_code) === $normalizedCode,
        ) ?? $costCenters->first(
            fn (SapCostCenterCache $candidate): bool => $this->normalize($candidate->center_name) === $normalizedName,
        );

        if (! $costCenter) {
            throw new SapSubmissionException("{$label} [{$localCode}] belum memiliki mapping cost center aktif pada database SAP {$companyDb}.");
        }

        return $costCenter;
    }

    private function normalize(string $value): string
    {
        return Str::lower(trim($value));
    }
}
