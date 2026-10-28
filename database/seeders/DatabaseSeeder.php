<?php

namespace Database\Seeders;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $pool = ControlPool::query()->updateOrCreate(
            ['code' => 'DUMAI'],
            ['external_id' => 'demo-pool-dumai', 'name' => 'Dumai', 'is_active' => true, 'synced_at' => now()],
        );
        $department = ControlDepartment::query()->updateOrCreate(
            ['external_id' => 'demo-department-tsp'],
            ['pool_id' => $pool->id, 'code' => 'TSP', 'name' => 'Transport', 'is_active' => true, 'synced_at' => now()],
        );

        $user = User::query()->updateOrCreate(['email' => 'admin@example.test'], [
            'name' => 'Admin Operasional',
            'password' => 'password',
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        UserOperationalProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['pool_id' => $pool->id, 'department_id' => $department->id, 'warehouse_code' => 'DMI-01'],
        );

        $this->call(DemoReferenceDataSeeder::class);
    }
}
