<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_open_the_password_page(): void
    {
        $user = $this->operationalUser();

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('password.edit'))
            ->assertOk()
            ->assertSee('Ganti Password')
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_user_can_change_own_password_and_remain_authenticated(): void
    {
        $user = $this->operationalUser('OldSecure123');
        $oldRememberToken = $user->remember_token;

        $response = $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'OldSecure123',
            'password' => 'NewSecure456',
            'password_confirmation' => 'NewSecure456',
        ]);

        $response
            ->assertRedirect(route('password.edit'))
            ->assertSessionHas('status', 'Password berhasil diperbarui. Session pada perangkat lain telah dinonaktifkan.');

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecure456', $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'event' => 'account.password_changed',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
        ]);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_current_password_must_match_before_password_is_changed(): void
    {
        $user = $this->operationalUser('OldSecure123');

        $this->from(route('password.edit'))
            ->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'Incorrect123',
                'password' => 'NewSecure456',
                'password_confirmation' => 'NewSecure456',
            ])
            ->assertRedirect(route('password.edit'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('OldSecure123', $user->fresh()->password));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'account.password_changed']);
    }

    public function test_new_password_must_be_strong_confirmed_and_different(): void
    {
        $user = $this->operationalUser('OldSecure123');

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'OldSecure123',
                'password' => 'weak',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'OldSecure123',
                'password' => 'OldSecure123',
                'password_confirmation' => 'OldSecure123',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_password_change_invalidates_a_session_using_the_old_password_hash(): void
    {
        $user = $this->operationalUser('OldSecure123');
        $oldSessionHash = Auth::guard('web')->hashPasswordForCookie($user->password);
        $user->password = 'NewSecure456';
        $user->save();

        $this->actingAs($user->fresh())
            ->withSession([
                'sap_company_db' => 'TEST_DB',
                'password_hash_web' => $oldSessionHash,
            ])
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_guest_cannot_open_or_update_password(): void
    {
        $this->get(route('password.edit'))->assertRedirect(route('login'));
        $this->put(route('password.update'))->assertRedirect(route('login'));
    }

    private function operationalUser(string $password = 'OldSecure123'): User
    {
        $pool = ControlPool::query()->create([
            'code' => 'DUMAI',
            'name' => 'Dumai',
            'is_active' => true,
        ]);
        $department = ControlDepartment::query()->create([
            'pool_id' => $pool->id,
            'external_id' => fake()->uuid(),
            'code' => 'TSP',
            'name' => 'Transport',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'password' => $password,
            'role' => UserRole::AdminOperational,
            'is_active' => true,
            'remember_token' => 'existing-remember-token',
        ]);
        UserOperationalProfile::query()->create([
            'user_id' => $user->id,
            'pool_id' => $pool->id,
            'department_id' => $department->id,
            'warehouse_code' => 'DMI-01',
        ]);

        return $user->load('operationalProfile.department');
    }
}
