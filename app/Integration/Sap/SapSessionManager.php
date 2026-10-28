<?php

namespace App\Integration\Sap;

use Illuminate\Cache\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SapSessionManager
{
    public function __construct(private readonly SapCompanyContext $companies) {}

    /** @return array{session_id: string, route_id: string|null} */
    public function session(): array
    {
        $companyDb = $this->companies->current();
        $cacheKey = $this->companyCacheKey(config('sap.session.cache_key'), $companyDb);
        $lockKey = $this->companyCacheKey(config('sap.session.lock_key'), $companyDb);
        $cached = $this->cache()->get($cacheKey);

        if ($this->valid($cached)) {
            return $cached;
        }

        return $this->cache()->lock($lockKey, 20)->block(10, function () use ($cacheKey, $companyDb) {
            $cached = $this->cache()->get($cacheKey);

            if ($this->valid($cached)) {
                return $cached;
            }

            $session = $this->login($companyDb);
            $this->cache()->put($cacheKey, $session, config('sap.session.ttl_seconds'));

            return $session;
        });
    }

    public function forget(): void
    {
        $this->cache()->forget($this->companyCacheKey(
            config('sap.session.cache_key'),
            $this->companies->current(),
        ));
    }

    /** @return array{session_id: string, route_id: string|null} */
    private function login(string $companyDb): array
    {
        $response = $this->request()->post(ltrim(config('sap.endpoints.login'), '/'), [
            'CompanyDB' => $companyDb,
            'UserName' => config('sap.username'),
            'Password' => config('sap.password'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Unable to authenticate to SAP Service Layer.');
        }

        $sessionId = $response->json('SessionId');

        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('SAP Service Layer login response did not include SessionId.');
        }

        return [
            'session_id' => $sessionId,
            'route_id' => $this->cookieValue($response->header('Set-Cookie'), 'ROUTEID'),
        ];
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('sap.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('SAP Service Layer URL is not configured.');
        }

        $request = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->connectTimeout(config('sap.connect_timeout_seconds'))
            ->timeout(config('sap.timeout_seconds'));

        return config('sap.verify_tls') ? $request : $request->withoutVerifying();
    }

    private function cache(): Repository
    {
        return Cache::store(config('sap.session.cache_store'));
    }

    private function valid(mixed $session): bool
    {
        return is_array($session) && is_string($session['session_id'] ?? null) && $session['session_id'] !== '';
    }

    private function companyCacheKey(string $baseKey, string $companyDb): string
    {
        return $baseKey.':'.hash('sha256', $companyDb);
    }

    private function cookieValue(?string $header, string $name): ?string
    {
        if (! $header || ! preg_match('/(?:^|[,;]\s*)'.preg_quote($name, '/').'=([^;,]+)/', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
