<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCostCenterCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private int $warehouseListStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB']);
        config()->set('sap.dimensions.pool', 2);
        config()->set('sap.dimensions.department', 1);
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');
        $this->fakeSapWarehouses();
    }

    public function test_admin_can_open_the_register_user_menu(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.users.create'));

        $response->assertOk()
            ->assertSee('Register User')
            ->assertSee('Jabatan Asli *')
            ->assertSee('Warehouse *')
            ->assertSee('Pool *')
            ->assertSee($admin->operationalProfile->pool->code)
            ->assertSee('Cost center aktif dimensi Pool pada database SAP TEST_DB.')
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
            ->assertSessionHas('status', 'User operasional berhasil didaftarkan. Pemetaan Pool, Department, dan Warehouse hanya berlaku untuk database TEST_DB.');

        $registeredUser = User::query()->where('email', $payload['email'])->firstOrFail();

        $this->assertSame(UserRole::ManagerOperational, $registeredUser->role);
        $this->assertSame('Staf Operasional', $registeredUser->job_title);
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
        SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'OPS',
            'center_name' => 'Operasional',
            'dimension' => 1,
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

    public function test_job_title_is_required_for_printed_user_identity(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin);
        $payload = [...$this->validPayload($pool, $department), 'job_title' => ''];

        $this->from(route('admin.users.create'))
            ->actingAs($admin)
            ->post(route('admin.users.store'), $payload)
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors(['job_title']);

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_edit_form_offers_pool_department_and_warehouse_of_the_selected_company(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, , $department] = $this->operationalUser(UserRole::AdminOperational);

        $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.users.edit', $registeredUser))
            ->assertOk()
            ->assertSee('Edit User')
            ->assertSee('Jabatan Asli *')
            ->assertSee($department->code)
            ->assertSee('name="department_id"', false)
            ->assertSee('name="company_db" value="TEST_DB"', false)
            ->assertSee('Kosongkan jika password tidak ingin diubah.');
    }

    public function test_edit_form_for_an_unmapped_company_starts_empty_and_never_copies_another_company(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, $pool, $department] = $this->operationalUser(UserRole::AdminOperational);
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);

        $response = $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.users.edit', ['user' => $registeredUser, 'company_db' => 'SECOND_DB']));

        $response->assertOk()
            ->assertSee('belum memiliki pemetaan untuk database')
            ->assertSee('name="company_db" value="SECOND_DB"', false)
            ->assertSee('name="warehouse_code" value=""', false)
            // No Pool/Department cost center exists in SECOND_DB, so nothing is offered for selection.
            ->assertDontSee($pool->code.' —', false)
            ->assertDontSee($department->code.' —', false);
    }

    public function test_editing_the_second_company_does_not_change_the_first_company_mapping(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, $pool, $department] = $this->operationalUser(UserRole::AdminOperational);
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
        $secondPool = SapCostCenterCache::query()->create([
            'company_db' => 'SECOND_DB',
            'center_code' => 'SMD',
            'center_name' => 'Samarinda',
            'dimension' => 2,
            'is_active' => true,
        ]);
        SapCostCenterCache::query()->create([
            'company_db' => 'SECOND_DB',
            'center_code' => $department->code,
            'center_name' => $department->name,
            'dimension' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $registeredUser), [
            ...$this->updatePayload($registeredUser, $secondPool, $department),
            'company_db' => 'SECOND_DB',
            'warehouse_code' => 'smd-01',
        ])->assertRedirect(route('admin.users.show', $registeredUser))->assertSessionHasNoErrors();

        $first = $registeredUser->operationalProfileFor('TEST_DB');
        $second = $registeredUser->operationalProfileFor('SECOND_DB');
        $this->assertSame($pool->id, $first->pool_id);
        $this->assertSame('DMI-01', $first->warehouse_code);
        $this->assertSame('SMD', $second->pool->code);
        $this->assertSame('SMD-01', $second->warehouse_code);
        $this->assertSame(2, $registeredUser->operationalProfiles()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.updated', 'auditable_id' => $registeredUser->id]);
    }

    public function test_department_without_an_exact_cost_center_code_in_the_company_is_rejected(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, $pool] = $this->operationalUser(UserRole::AdminOperational);
        config()->set('sap.company_databases', ['TEST_DB', 'SECOND_DB']);
        $secondPool = SapCostCenterCache::query()->create([
            'company_db' => 'SECOND_DB',
            'center_code' => 'SMD',
            'center_name' => 'Samarinda',
            'dimension' => 2,
            'is_active' => true,
        ]);
        // Same department NAME exists in SECOND_DB under another code: a similar name is not a mapping.
        $department = $registeredUser->operationalProfileFor('TEST_DB')->department;
        SapCostCenterCache::query()->create([
            'company_db' => 'SECOND_DB',
            'center_code' => 'OTHER-CODE',
            'center_name' => $department->name,
            'dimension' => 1,
            'is_active' => true,
        ]);

        $this->from(route('admin.users.edit', $registeredUser))
            ->actingAs($admin)
            ->put(route('admin.users.update', $registeredUser), [
                ...$this->updatePayload($registeredUser, $secondPool, $department),
                'company_db' => 'SECOND_DB',
            ])
            ->assertSessionHasErrors('department_id');

        $this->assertNull($registeredUser->operationalProfileFor('SECOND_DB'));
    }

    public function test_pool_that_is_inactive_or_of_another_dimension_in_the_company_is_rejected(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, , $department] = $this->operationalUser(UserRole::AdminOperational);
        $inactivePool = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'OFF',
            'center_name' => 'Pool Nonaktif',
            'dimension' => 2,
            'is_active' => false,
        ]);
        $vehicleDimension = SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => 'B1234XX',
            'center_name' => 'Kendaraan',
            'dimension' => 3,
            'is_active' => true,
        ]);

        foreach ([$inactivePool, $vehicleDimension] as $invalidPool) {
            $this->actingAs($admin)
                ->put(route('admin.users.update', $registeredUser), $this->updatePayload($registeredUser, $invalidPool, $department))
                ->assertSessionHasErrors('pool_cost_center_id');
        }
    }

    public function test_an_unchanged_warehouse_that_sap_has_since_deactivated_is_rejected_on_edit(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, $pool, $department] = $this->operationalUser(UserRole::AdminOperational);
        $registeredUser->operationalProfileFor('TEST_DB')->update(['warehouse_code' => 'OLD-01']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $registeredUser), [
                ...$this->updatePayload($registeredUser, $this->poolCostCenter($pool), $department),
                'warehouse_code' => 'OLD-01',
            ])
            ->assertSessionHasErrors('warehouse_code');
    }

    public function test_warehouse_missing_in_the_selected_company_is_rejected_and_unreadable_sap_fails_closed(): void
    {
        [$admin] = $this->operationalUser(UserRole::SuperAdmin);
        [$registeredUser, $pool, $department] = $this->operationalUser(UserRole::AdminOperational);
        $payload = $this->updatePayload($registeredUser, $this->poolCostCenter($pool), $department);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $registeredUser), [...$payload, 'warehouse_code' => 'NOPE-99'])
            ->assertSessionHasErrors('warehouse_code');
        $this->assertSame('DMI-01', $registeredUser->operationalProfileFor('TEST_DB')->warehouse_code);

        Cache::flush();
        $this->warehouseListStatus = 500;

        $this->actingAs($admin)
            ->put(route('admin.users.update', $registeredUser), [...$payload, 'warehouse_code' => 'DMI-02'])
            ->assertSessionHasErrors('warehouse_code');
        $this->assertSame('DMI-01', $registeredUser->operationalProfileFor('TEST_DB')->warehouse_code);
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
            'job_title' => 'Supervisor Transport',
            'email' => 'updated.user@example.test',
            'password' => 'UpdatedPass123',
            'password_confirmation' => 'UpdatedPass123',
            'role' => UserRole::ManagerOperational->value,
            'pool_cost_center_id' => $newPoolCostCenter->id,
            'department_id' => $department->id,
            'warehouse_code' => 'mdn-01',
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('admin.users.show', $registeredUser))
            ->assertSessionHas('status', 'Data user berhasil diperbarui. Pemetaan operasional yang disimpan hanya untuk database TEST_DB.')
            ->assertSessionHasNoErrors();

        $registeredUser->refresh()->load('operationalProfile.pool');
        $this->assertSame('User Operasional Diperbarui', $registeredUser->name);
        $this->assertSame('Supervisor Transport', $registeredUser->job_title);
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
        [$registeredUser, $pool, $department] = $this->operationalUser(UserRole::AdminOperational);
        $originalPassword = $registeredUser->password;

        $this->actingAs($admin)->put(route('admin.users.update', $registeredUser), [
            ...$this->updatePayload($registeredUser, $this->poolCostCenter($pool), $department),
            'password' => '',
            'password_confirmation' => '',
            'warehouse_code' => 'DMI-02',
        ])->assertRedirect(route('admin.users.show', $registeredUser));

        $this->assertSame($originalPassword, $registeredUser->refresh()->password);
    }

    public function test_admin_ho_cannot_edit_or_update_a_super_admin(): void
    {
        [$adminHo] = $this->operationalUser(UserRole::AdminHo);
        [$superAdmin, $pool] = $this->operationalUser(UserRole::SuperAdmin);
        $payload = [
            'name' => $superAdmin->name,
            'job_title' => $superAdmin->job_title,
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
        [$registeredUser, $pool, $department] = $this->operationalUser(UserRole::AdminOperational);

        $this->actingAs($adminHo)->put(route('admin.users.update', $registeredUser), [
            ...$this->updatePayload($registeredUser, $this->poolCostCenter($pool), $department),
            'name' => 'Admin Operasional Updated',
            'job_title' => 'Admin Operasional Area',
            'warehouse_code' => 'SBG-01',
        ])->assertRedirect(route('admin.users.show', $registeredUser));

        $registeredUser->refresh()->load('operationalProfile');
        $this->assertSame('Admin Operasional Updated', $registeredUser->name);
        $this->assertSame('Admin Operasional Area', $registeredUser->job_title);
        $this->assertSame('SBG-01', $registeredUser->operationalProfile->warehouse_code);
    }

    public function test_user_cannot_deactivate_own_account(): void
    {
        [$admin, $pool, $department] = $this->operationalUser(UserRole::SuperAdmin);

        $this->from(route('admin.users.edit', $admin))
            ->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                ...$this->updatePayload($admin, $this->poolCostCenter($pool), $department),
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
        SapCostCenterCache::query()->create([
            'company_db' => 'TEST_DB',
            'center_code' => $department->code,
            'center_name' => $department->name,
            'dimension' => 1,
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        UserOperationalProfile::query()->create([
            'company_db' => 'TEST_DB',
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
            'job_title' => 'Staf Operasional',
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

    /** @return array<string, mixed> */
    private function updatePayload(User $user, SapCostCenterCache $poolCostCenter, ControlDepartment $department): array
    {
        return [
            'name' => $user->name,
            'job_title' => $user->job_title,
            'email' => $user->email,
            'password' => '',
            'password_confirmation' => '',
            'role' => $user->role->value,
            'company_db' => 'TEST_DB',
            'pool_cost_center_id' => $poolCostCenter->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
            'is_active' => '1',
        ];
    }

    /** Fake SAP Warehouses for any company (GET only); the portal reads active warehouses to validate a mapping. */
    private function fakeSapWarehouses(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/Login')) {
                return Http::response(['SessionId' => 'session-1']);
            }

            if ($this->warehouseListStatus !== 200) {
                return Http::response([], $this->warehouseListStatus);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['value' => (int) ($query['$skip'] ?? 0) > 0 ? [] : collect(['DMI-01', 'DMI-02', 'MDN-01', 'SBG-01', 'SMD-01'])
                ->map(fn (string $code): array => ['WarehouseCode' => $code])->all()]);
        });
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
