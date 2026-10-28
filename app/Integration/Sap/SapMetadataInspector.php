<?php

namespace App\Integration\Sap;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class SapMetadataInspector
{
    public function __construct(
        private readonly SapServiceLayerClient $client,
        private readonly SapCompanyContext $companies,
    ) {}

    public function supportsProperty(string $property): bool
    {
        return in_array($property, $this->propertyNames(), true);
    }

    public function supportsEntityProperty(string $entitySet, string $property): bool
    {
        return in_array($property, $this->entityPropertyNames($entitySet), true);
    }

    /** @return list<string> */
    private function propertyNames(): array
    {
        preg_match_all('/<Property\b[^>]*\bName\s*=\s*["\']([^"\']+)["\']/i', $this->metadata(), $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** @return list<string> */
    private function entityPropertyNames(string $entitySet): array
    {
        $metadata = $this->metadata();
        $quotedEntitySet = preg_quote($entitySet, '/');
        $entitySetPattern = '/<EntitySet\b(?=[^>]*\bName\s*=\s*["\']'.$quotedEntitySet.'["\'])(?=[^>]*\bEntityType\s*=\s*["\']([^"\']+)["\'])[^>]*>/i';

        if (preg_match($entitySetPattern, $metadata, $entitySetMatch) !== 1) {
            return [];
        }

        $qualifiedEntityType = (string) $entitySetMatch[1];
        $entityType = str_contains($qualifiedEntityType, '.')
            ? substr($qualifiedEntityType, (int) strrpos($qualifiedEntityType, '.') + 1)
            : $qualifiedEntityType;
        $quotedEntityType = preg_quote($entityType, '/');
        $entityTypePattern = '/<EntityType\b(?=[^>]*\bName\s*=\s*["\']'.$quotedEntityType.'["\'])[^>]*>(.*?)<\/EntityType>/is';

        if (preg_match($entityTypePattern, $metadata, $entityTypeMatch) !== 1) {
            return [];
        }

        preg_match_all('/<Property\b[^>]*\bName\s*=\s*["\']([^"\']+)["\']/i', $entityTypeMatch[1], $propertyMatches);

        return array_values(array_unique($propertyMatches[1] ?? []));
    }

    private function metadata(): string
    {
        return $this->cache()->remember(
            'sap:metadata:'.sha1($this->companies->current()),
            now()->addSeconds((int) config('sap.metadata.ttl_seconds', 3600)),
            fn (): string => $this->client->request('GET', '/$metadata')->body(),
        );
    }

    private function cache(): Repository
    {
        return Cache::store(config('sap.session.cache_store'));
    }
}
