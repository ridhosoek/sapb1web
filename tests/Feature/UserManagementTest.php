<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCostCenterCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB']);
        config()->set('sap.dimensions.pool', 2);
    }

    public function test_admin_can_open_the_register_user_menu(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.users.create'));

        $response->assertOk()
            ->assertSee('Register User')
            ->assertSee('Warehouse *')
            ->assertSee('Pool *')
            ->assertSee($admin->operationalProfile->pool->code)
            ->assertSee('Cost center aktif dimensi Pool dari database SAP yang sedang login.')
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

    public function test_pool_cost_center_is_independent_from_the_selected_department(): void
    {
        [$admin, $pool] = $this->operationalUser(UserRole::SuperAdmin);
        $otherPoolCostCenter = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'MEDAN',
            'center_name' => 'Medan',
            'dimension' => 2,
            'is_active' => true,
        ]);
        $otherDepartment = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => 'dept-medan',
            'code' => 'OPS',
            'name' => 'Operasional',
            'is_active' => true,
        ]);
        $payload = [
            ...$this->validPayload($pool, $otherDepartment),
            'pool_cost_center_id' => $otherPoolCostCenter->id,
        ];

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $payload)
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $registeredUser = User::query()
            ->with('operationalProfile.pool')
            ->where('email', $payload['email'])
            ->firstOrFail();

        $this->assertSame('MEDAN', $registeredUser->operationalProfile->pool->code);
        $this->assertSame($otherDepartment->id, $registeredUser->operationalProfile->department_id);
    }

    public function test_pool_cost_center_from_another_sap_database_is_rejected(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin);
        $otherCompanyPool = SapCostCenterCache::query()->create([
            'company_db' => 'SECOND_DB',
            'center_code' => 'MEDAN',
            'center_name' => 'Medan',
            'dimension' => 2,
            'is_active' => true,
        ]);
        $payload = [
            ...$this->validPayload($pool, $department),
            'pool_cost_center_id' => $otherCompanyPool->id,
        ];

        $this->from(route('admin.users.create'))
            ->actingAs($admin)
            ->post(route('admin.users.store'), $payload)
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors(['pool_cost_center_id']);

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
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

    public function test_super_admin_can_edit_user_without_editing_department(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, , $department] = $this->operationalUser(UserRole::AdminOperational);

        $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.users.edit', $registeredUser))
            ->assertOk()
            ->assertSee('Edit User')
            ->assertSee($department->code)
            ->assertDontSee('name="department_id"', false)
            ->assertSee('Kosongkan jika password tidak ingin diubah.');
    }

    public function test_super_admin_can_update_user_pool_password_warehouse_role_and_status(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, , $department] = $this->operationalUser(UserRole::AdminOperational);
        $newPoolCostCenter = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'MDN',
            'center_name' => 'Medan',
            'dimension' => 2,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $registeredUser), [
            'name' => 'User Operasional Diperbarui',
            'email' => 'updated.user@example.test',
            'password' => 'UpdatedPass123',
            'password_confirmation' => 'UpdatedPass123',
            'role' => UserRole::ManagerOperational->value,
            'pool_cost_center_id' => $newPoolCostCenter->id,
            'warehouse_code' => 'mdn-01',
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('admin.users.show', $registeredUser))
            ->assertSessionHas('status', 'Data user berhasil diperbarui.')
            ->assertSessionHasNoErrors();

        $registeredUser->refresh()->load('operationalProfile.pool');
        $this->assertSame('User Operasional Diperbarui', $registeredUser->name);
        $this->assertSame('updated.user@example.test', $registeredUser->email);
        $this->assertSame(UserRole::ManagerOperational, $registeredUser->role);
        $this->assertFalse($registeredUser->is_active);
        $this->assertTrue(Hash::check('UpdatedPass123', $registeredUser->password));
        $this->assertNotNull($registeredUser->remember_token);
        $this->assertSame('MDN', $registeredUser->operationalProfile->pool->code);
        $this->assertSame('MDN-01', $registeredUser->operationalProfile->warehouse_code);
        $this->assertSame($department->id, $registeredUser->operationalProfile->department_id);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'event' => 'user.updated',
            'auditable_type' => User::class,
            'auditable_id' => $registeredUser->id,
        ]);
    }

    public function test_blank_password_keeps_the_existing_user_password(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, $pool] = $this->operationalUser(UserRole::AdminOperational);
        $originalPassword = $registeredUser->password;

        $this->actingAs($admin)->put(route('admin.users.update', $registeredUser), [
            'name' => $registeredUser->name,
            'email' => $registeredUser->email,
            'password' => '',
            'password_confirmation' => '',
            'role' => $registeredUser->role->value,
            'pool_cost_center_id' => $this->poolCostCenter($pool)->id,
            'warehouse_code' => 'DMI-02',
            'is_active' => '1',
        ])->assertRedirect(route('admin.users.show', $registeredUser));

        $this->assertSame($originalPassword, $registeredUser->refresh()->password);
    }

    public function test_admin_ho_cannot_edit_or_update_a_super_admin(): void
    {
        [$adminHo] = $this->operationalUser(UserRole::AdminHo);
        [$superAdmin, $pool] = $this->operationalUser(UserRole::SuperAdmin);
        $payload = [
            'name' => $superAdmin->name,
            'email' => $superAdmin->email,
            'password' => '',
            'password_confirmation' => '',
            'role' => UserRole::SuperAdmin->value,
            'pool_cost_center_id' => $this->poolCostCenter($pool)->id,
            'warehouse_code' => 'DMI-01',
            'is_active' => '1',
        ];

        $this->actingAs($adminHo)->get(route('admin.users.edit', $superAdmin))->assertForbidden();
        $this->actingAs($adminHo)->put(route('admin.users.update', $superAdmin), $payload)->assertForbidden();
    }

    public function test_admin_ho_can_update_an_operational_user(): void
    {
        [$adminHo] = $this->operationalUser(UserRole::AdminHo);
        [$registeredUser, $pool] = $this->operationalUser(UserRole::AdminOperational);

        $this->actingAs($adminHo)->put(route('admin.users.update', $registeredUser), [
            'name' => 'Admin Operasional Updated',
            'email' => $registeredUser->email,
            'password' => '',
            'password_confirmation' => '',
            'role' => UserRole::AdminOperational->value,
            'pool_cost_center_id' => $this->poolCostCenter($pool)->id,
            'warehouse_code' => 'SBG-01',
            'is_active' => '1',
        ])->assertRedirect(route('admin.users.show', $registeredUser));

        $registeredUser->refresh()->load('operationalProfile');
        $this->assertSame('Admin Operasional Updated', $registeredUser->name);
        $this->assertSame('SBG-01', $registeredUser->operationalProfile->warehouse_code);
    }

    public function test_user_cannot_deactivate_own_account(): void
    {
        [$admin, $pool] = $this->operationalUser(UserRole::SuperAdmin);

        $this->from(route('admin.users.edit', $admin))
            ->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => $admin->role->value,
                'pool_cost_center_id' => $this->poolCostCenter($pool)->id,
                'warehouse_code' => 'DMI-01',
                'is_active' => '0',
            ])
            ->assertRedirect(route('admin.users.edit', $admin))
            ->assertSessionHasErrors(['is_active']);

        $this->assertTrue($admin->refresh()->is_active);
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
        SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => $pool->code,
            'center_name' => $pool->name,
            'dimension' => 2,
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
        $poolCostCenter = SapCostCenterCache::query()
            ->where('company_db', 'TEST_DB')
            ->where('dimension', 2)
            ->where('center_code', $pool->code)
            ->firstOrFail();

        return [
            'name' => 'User Operasional Baru',
            'email' => 'new.user@example.test',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'role' => $role->value,
            'pool_cost_center_id' => $poolCostCenter->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-02',
            'is_active' => '1',
        ];
    }

    private function poolCostCenter(ControlPool $pool): SapCostCenterCache
    {
        return SapCostCenterCache::query()
            ->where('company_db', 'TEST_DB')
            ->where('dimension', 2)
            ->where('center_code', $pool->code)
            ->firstOrFail();
    }
}
