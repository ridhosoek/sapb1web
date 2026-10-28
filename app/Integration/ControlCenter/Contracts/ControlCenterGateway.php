<?php

namespace App\Integration\ControlCenter\Contracts;

interface ControlCenterGateway
{
    /** @return list<array<string, mixed>> */
    public function getPools(): array;

    /** @return list<array<string, mixed>> */
    public function getDepartments(): array;

    /** @return list<array<string, mixed>> */
    public function getVehiclesByPool(string $poolExternalId): array;

    /** @return array<string, mixed>|null */
    public function getVehicle(string $vehicleExternalId): ?array;

    /** @return array<string, mixed>|null */
    public function getUserOperationalContext(string $userExternalId): ?array;
}
