<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class ErrorAndSecurityResponseTest extends TestCase
{
    public function test_html_server_error_uses_friendly_page_without_exception_details(): void
    {
        config()->set('app.debug', false);
        Route::get('/test-friendly-error', fn () => abort(500, 'sensitive internal exception'));

        $this->get('/test-friendly-error')
            ->assertServerError()
            ->assertSee('Data belum dapat ditampilkan')
            ->assertSee('Refresh Halaman')
            ->assertDontSee('sensitive internal exception');
    }

    public function test_responses_include_strict_browser_security_headers_and_request_id(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('x-frame-options', 'DENY')
            ->assertHeader('referrer-policy', 'no-referrer')
            ->assertHeader('cross-origin-opener-policy', 'same-origin')
            ->assertHeader('x-request-id');
    }

    public function test_database_connection_error_uses_specific_service_unavailable_page(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        config()->set('app.debug', false);
        Route::get('/test-database-connection-error', function (): never {
            $previous = new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002);

            throw new QueryException('mysql', 'select * from users', [], $previous);
        });

        $this->get('/test-database-connection-error')
            ->assertServiceUnavailable()
            ->assertSee('Database portal belum tersambung')
            ->assertSee('Pastikan layanan MySQL aktif')
            ->assertDontSee('select * from users');
    }

    public function test_expired_or_missing_login_session_redirects_to_login_instead_of_server_error(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_static_offline_page_is_available_for_reverse_proxy_fallback(): void
    {
        $path = public_path('offline.html');

        $this->assertFileExists($path);
        $this->assertStringContainsString('Website sedang tidak tersedia', file_get_contents($path) ?: '');
    }
}
