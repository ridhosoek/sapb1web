<?php

namespace Tests\Feature;

use App\Application\ControlCenter\SyncControlCenterCache;
use App\Integration\ControlCenter\Contracts\ControlCenterGateway;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ControlCenterSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sync_caches_operational_master_data(): void
    {
        $this->app->instance(ControlCenterGateway::class, new class implements ControlCenterGateway
        {
            public function getPools(): array
            {
                return [['id' => 'p1', 'code' => 'DUMAI', 'name' => 'Dumai', 'is_active' => true]];
            }

            public function getDepartments(): array
            {
                return [['id' => 'd1', 'pool_code' => 'DUMAI', 'code' => 'TSP', 'name' => 'Transport', 'is_active' => true]];
            }

            public function getVehiclesByPool(string $poolExternalId): array
            {
                return [['id' => 'v1', 'nopol' => 'bk8748es', 'description' => 'Mobil Tangki', 'last_known_km' => 789120]];
            }

            public function getVehicle(string $vehicleExternalId): ?array
            {
                return null;
            }

            public function getUserOperationalContext(string $userExternalId): ?array
            {
                return null;
            }
        });

        $result = $this->app->make(SyncControlCenterCache::class)->handle();

        $this->assertSame(['pools' => 1, 'departments' => 1, 'vehicles' => 1], $result);
        $this->assertDatabaseHas('control_vehicles', [
            'license_plate' => 'BK8748ES',
            'last_known_km' => 789120,
        ]);
    }
}
