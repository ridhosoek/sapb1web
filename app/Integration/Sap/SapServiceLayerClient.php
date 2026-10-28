<?php

namespace App\Integration\Sap;

use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Models\SapApiLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SapServiceLayerClient
{
    public function __construct(
        private readonly SapSessionManager $sessions,
        private readonly SapErrorMapper $errors,
        private readonly SapCompanyContext $companies,
    ) {}

    /** @param array<string, mixed> $options */
    public function request(string $method, string $endpoint, array $options = []): Response
    {
        $correlationId = (string) Str::uuid();
        $started = hrtime(true);
        $response = null;

        try {
            $response = $this->send($method, $endpoint, $options);

            if ($response->status() === 401) {
                $this->sessions->forget();
                $response = $this->send($method, $endpoint, $options);
            }

            if ($response->failed()) {
                throw $this->errors->fromResponse($response);
            }

            $this->log($correlationId, $method, $endpoint, $response, $started, 'SUCCESS');

            return $response;
        } catch (Throwable $exception) {
            $this->log($correlationId, $method, $endpoint, $response, $started, 'ERROR', $exception);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $endpoint, array $options): Response
    {
        $session = $this->sessions->session();
        $cookies = 'B1SESSION='.$session['session_id'];

        if ($session['route_id']) {
            $cookies .= '; ROUTEID='.$session['route_id'];
        }

        return $this->requestFactory()
            ->withHeaders(['Cookie' => $cookies])
            ->send(strtoupper($method), ltrim($endpoint, '/'), $options);
    }

    private function requestFactory(): PendingRequest
    {
        $baseUrl = config('sap.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('SAP Service Layer URL is not configured.');
        }

        $request = Http::baseUrl(rtrim($baseUrl, '/').'/')
            ->acceptJson()
            ->connectTimeout(config('sap.connect_timeout_seconds'))
            ->timeout(config('sap.timeout_seconds'));

        return config('sap.verify_tls') ? $request : $request->withoutVerifying();
    }

    private function log(string $correlationId, string $method, string $endpoint, ?Response $response, int $started, string $result, ?Throwable $error = null): void
    {
        try {
            SapApiLog::query()->create([
                'company_db' => $this->companies->current(),
                'correlation_id' => $correlationId,
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'response_status' => $response?->status(),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'result' => $result,
                'error_code' => $error instanceof SapBusinessException ? $error->sapCode : ($error ? (string) $error->getCode() : null),
                'error_message' => $error instanceof SapBusinessException ? $error->technicalMessage : $error?->getMessage(),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Integration logging must never replace the original response or exception.
        }
    }
}
