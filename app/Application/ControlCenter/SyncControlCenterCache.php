<?php

namespace App\Application\ControlCenter;

use App\Integration\ControlCenter\Contracts\ControlCenterGateway;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\ControlVehicle;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncControlCenterCache
{
    public function __construct(private readonly ControlCenterGateway $gateway) {}

    /** @return array{pools: int, departments: int, vehicles: int} */
    public function handle(): array
    {
        $poolRows = $this->gateway->getPools();
        $departmentRows = $this->gateway->getDepartments();

        DB::transaction(function () use ($poolRows, $departmentRows) {
            $this->syncPools($poolRows);
            $this->syncDepartments($departmentRows);
        });

        $vehicles = 0;
        foreach (ControlPool::query()->where('is_active', true)->get() as $pool) {
            $externalId = $pool->external_id ?: $pool->code;
            $vehicleRows = $this->gateway->getVehiclesByPool($externalId);
            DB::transaction(fn () => $this->syncVehicles($pool, $vehicleRows));
            $vehicles += count($vehicleRows);
        }

        return ['pools' => count($poolRows), 'departments' => count($departmentRows), 'vehicles' => $vehicles];
    }

    /** @param list<array<string, mixed>> $rows */
    private function syncPools(array $rows): int
    {
        foreach ($rows as $row) {
            $code = $this->required($row, ['code', 'pool_code', 'PoolCode']);
            ControlPool::query()->updateOrCreate(
                ['code' => $code],
                [
                    'external_id' => $this->value($row, ['id', 'external_id', 'Id']),
                    'name' => $this->required($row, ['name', 'pool_name', 'Name']),
                    'is_active' => (bool) $this->value($row, ['is_active', 'active', 'Active'], true),
                    'synced_at' => now(),
                ],
            );
        }

        return count($rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function syncDepartments(array $rows): int
    {
        foreach ($rows as $row) {
            $poolCode = $this->value($row, ['pool_code', 'PoolCode']);
            $poolId = $poolCode ? ControlPool::query()->where('code', $poolCode)->value('id') : null;
            $code = $this->required($row, ['code', 'department_code', 'DepartmentCode']);

            ControlDepartment::query()->updateOrCreate(
                ['external_id' => $this->value($row, ['id', 'external_id', 'Id']) ?: $code],
                [
                    'pool_id' => $poolId,
                    'code' => $code,
                    'name' => $this->required($row, ['name', 'department_name', 'Name']),
                    'is_active' => (bool) $this->value($row, ['is_active', 'active', 'Active'], true),
                    'synced_at' => now(),
                ],
            );
        }

        return count($rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function syncVehicles(ControlPool $pool, array $rows): int
    {
        foreach ($rows as $row) {
            $plate = strtoupper($this->required($row, ['license_plate', 'nopol', 'plate_number', 'Nopol']));
            ControlVehicle::query()->updateOrCreate(
                ['license_plate' => $plate],
                [
                    'pool_id' => $pool->id,
                    'external_id' => $this->value($row, ['id', 'external_id', 'Id']),
                    'description' => $this->value($row, ['description', 'vehicle_name', 'Description']),
                    'last_known_km' => $this->value($row, ['last_known_km', 'odometer', 'LastKm']),
                    'is_active' => (bool) $this->value($row, ['is_active', 'active', 'Active'], true),
                    'synced_at' => now(),
                ],
            );
        }

        return count($rows);
    }

    /** @param list<string> $keys */
    private function required(array $row, array $keys): string
    {
        $value = $this->value($row, $keys);

        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new RuntimeException('Control Center response is missing a required field: '.implode('|', $keys));
        }

        return trim((string) $value);
    }

    /** @param list<string> $keys */
    private function value(array $row, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (Arr::exists($row, $key)) {
                return $row[$key];
            }
        }

        return $default;
    }
}
