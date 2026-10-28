<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_user_can_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-password', 'is_active' => true]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
            'sap_company_db' => 'SECOND_DB',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('sap_company_db', 'SECOND_DB');
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-password', 'is_active' => false]);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
            'sap_company_db' => 'TEST_DB',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'incorrect',
                'sap_company_db' => 'TEST_DB',
            ]);
        }

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect',
            'sap_company_db' => 'TEST_DB',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Terlalu banyak percobaan login.', session('errors')->first('email'));
        RateLimiter::clear(Str::transliterate(Str::lower($user->email).'|127.0.0.1'));
    }

    public function test_login_page_lists_only_configured_sap_company_databases(): void
    {
        $this->withoutVite()
            ->get(route('login'))
            ->assertOk()
            ->assertSee('<option value="TEST_DB"', false)
            ->assertSee('<option value="SECOND_DB"', false);
    }

    public function test_user_cannot_select_an_unconfigured_sap_company_database(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'secret-password',
            'sap_company_db' => 'UNTRUSTED_DB',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('sap_company_db');
        $this->assertGuest();
    }
}
