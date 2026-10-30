<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_open_the_register_user_menu(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.users.create'));

        $response->assertOk()
            ->assertSee('Register User')
            ->assertSee('Warehouse *')
            ->assertDontSee('Pool *')
            ->assertSee('Batasan Role')
            ->assertSee('Admin Operasional')
            ->assertSee('Manager Operasional / Transport')
            ->assertSee('Admin HO')
            ->assertSee('Manager Admin HO')
            ->assertSee('Super Admin');
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_non_admin_cannot_open_or_submit_user_registration(UserRole $role): void
    {
        [$user, $pool, $department] = $this->operationalUser($role);
        $payload = $this->validPayload($pool, $department);

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.users.store'), $payload)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_admin_can_register_user_with_operational_profile_and_allowed_role(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin);
        $payload = $this->validPayload($pool, $department, UserRole::ManagerOperational);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), $payload);

        $response->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'User operasional berhasil didaftarkan.');

        $registeredUser = User::query()->where('email', $payload['email'])->firstOrFail();

        $this->assertSame(UserRole::ManagerOperational, $registeredUser->role);
        $this->assertTrue($registeredUser->is_active);
        $this->assertTrue(Hash::check($payload['password'], $registeredUser->password));
        $this->assertDatabaseHas('user_operational_profiles', [
            'user_id' => $registeredUser->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-02',
        ]);
    }

    public function test_pool_is_derived_from_department_instead_of_user_input(): void
    {
        [$admin, $pool] = $this->operationalUser(UserRole::SuperAdmin);
        $otherPool = ControlPool::query()->create(['code' => 'MEDAN', 'name' => 'Medan', 'is_active' => true]);
        $otherDepartment = ControlDepartment::query()->create([
            'pool_id' => $otherPool->id,
            'external_id' => 'dept-medan',
            'code' => 'OPS',
            'name' => 'Operasional',
            'is_active' => true,
        ]);
        $payload = $this->validPayload($pool, $otherDepartment);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $payload)
            ->assertRedirect(route('admin.users.index'));

        $registeredUser = User::query()->where('email', $payload['email'])->firstOrFail();

        $this->assertDatabaseHas('user_operational_profiles', [
            'user_id' => $registeredUser->id,
            'pool_id' => $otherPool->id,
            'department_id' => $otherDepartment->id,
            'warehouse_code' => 'DMI-02',
        ]);
    }

    public function test_warehouse_is_required_for_user_scope(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin);
        $payload = [...$this->validPayload($pool, $department), 'warehouse_code' => ''];

        $this->from(route('admin.users.create'))
            ->actingAs($admin)
            ->post(route('admin.users.store'), $payload)
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors(['warehouse_code']);

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_arbitrary_role_is_rejected(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin);
        $payload = [...$this->validPayload($pool, $department), 'role' => 'SUPER_ADMIN'];

        $response = $this->from(route('admin.users.create'))
            ->actingAs($admin)
            ->post(route('admin.users.store'), $payload);

        $response->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors(['role']);
        $this->assertSame('Anda tidak diizinkan membuat user dengan role tersebut.', session('errors')->first('role'));
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_weak_password_is_rejected(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin);
        $payload = [
            ...$this->validPayload($pool, $department),
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        $response = $this->from(route('admin.users.create'))
            ->actingAs($admin)
            ->post(route('admin.users.store'), $payload);

        $response->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors(['password']);
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_registered_user_name_is_escaped_in_the_user_list(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin);
        $payload = [
            ...$this->validPayload($pool, $department),
            'name' => '<script>alert("xss")</script>',
        ];

        $this->actingAs($admin)->post(route('admin.users.store'), $payload)->assertRedirect();

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.users.index'));

        $response->assertOk()
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("xss")</script>', false);
    }

    public function test_admin_ho_can_register_operational_roles_but_cannot_escalate_privileges(): void
    {
        [$adminHo, $pool, $department] = $this->operationalUser(UserRole::AdminHo);
        $operationalPayload = $this->validPayload($pool, $department, UserRole::AdminOperational);

        $this->actingAs($adminHo)
            ->post(route('admin.users.store'), $operationalPayload)
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => $operationalPayload['email'],
            'role' => UserRole::AdminOperational->value,
        ]);

        $managerHoPayload = [
            ...$this->validPayload($pool, $department, UserRole::ManagerAdminHo),
            'email' => 'manager.ho@example.test',
        ];

        $this->actingAs($adminHo)
            ->post(route('admin.users.store'), $managerHoPayload)
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => $managerHoPayload['email']]);
    }

    /** @return array<string, array{UserRole}> */
    public static function unauthorizedRoles(): array
    {
        return [
            'admin operational' => [UserRole::AdminOperational],
            'manager operational' => [UserRole::ManagerOperational],
            'manager admin HO' => [UserRole::ManagerAdminHo],
        ];
    }

    /** @return array{User, ControlPool, ControlDepartment} */
    private function operationalUser(UserRole $role): array
    {
        $suffix = strtolower($role->value).'-'.fake()->unique()->numerify('###');
        $pool = ControlPool::query()->create([
            'code' => 'POOL-'.$suffix,
            'name' => 'Pool '.$suffix,
            'is_active' => true,
        ]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-'.$suffix,
            'code' => 'DEPT-'.$suffix,
            'name' => 'Department '.$suffix,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);

        return [$user, $pool, $department];
    }

    /** @return array<string, mixed> */
    private function validPayload(
        ControlPool $pool,
        ControlDepartment $department,
        UserRole $role = UserRole::AdminOperational,
    ): array {
        return [
            'name' => 'User Operasional Baru',
            'email' => 'new.user@example.test',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'role' => $role->value,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-02',
            'is_active' => '1',
        ];
    }
}
