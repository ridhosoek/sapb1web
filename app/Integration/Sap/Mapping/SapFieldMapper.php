<?php

namespace App\Integration\Sap\Mapping;

use InvalidArgumentException;

class SapFieldMapper
{
    public function field(string $businessName): string
    {
        $field = config("sap.fields.{$businessName}");

        if (! is_string($field) || $field === '') {
            throw new InvalidArgumentException("SAP field mapping [{$businessName}] is not configured.");
        }

        return $field;
    }

    public function optionalField(string $businessName): ?string
    {
        $field = config("sap.fields.{$businessName}");

        return is_string($field) && trim($field) !== '' ? trim($field) : null;
    }

    /**
     * @param  array<string, mixed>  $businessValues
     * @return array<string, mixed>
     */
    public function map(array $businessValues): array
    {
        $mapped = [];

        foreach ($businessValues as $name => $value) {
            $mapped[$this->field($name)] = $value;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $businessValues
     * @return array<string, mixed>
     */
    public function mapConfigured(array $businessValues): array
    {
        $mapped = [];

        foreach ($businessValues as $name => $value) {
            $field = $this->optionalField($name);

            if ($field !== null && $value !== null && $value !== '') {
                $mapped[$field] = $value;
            }
        }

        return $mapped;
    }
}
