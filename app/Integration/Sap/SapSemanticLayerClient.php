<?php

namespace App\Integration\Sap;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SapSemanticLayerClient
{
    public function __construct(private readonly SapSessionManager $sessions) {}

    /** @param array<string, mixed> $query */
    public function getSerialStock(array $query): Response
    {
        $entity = config('sap.goods_issue.serial_selection.entity');

        if (! is_string($entity) || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $entity) !== 1) {
            throw new RuntimeException('SAP serial view name is not configured correctly.');
        }

        $response = $this->send($entity, $query);

        if ($response->status() === 401) {
            $this->sessions->forgetSerialView();
            $response = $this->send($entity, $query);
        }

        return $response->throw();
    }

    /** @param array<string, mixed> $query */
    private function send(string $entity, array $query): Response
    {
        $session = $this->sessions->serialViewSession();
        $cookies = 'B1SESSION='.$session['session_id'];

        if ($session['route_id']) {
            $cookies .= '; ROUTEID='.$session['route_id'];
        }

        return $this->requestFactory()->withHeaders([
            'Cookie' => $cookies,
            'Prefer' => 'odata.maxpagesize='.(int) ($query['$top'] ?? 50),
        ])->get($entity, $query);
    }

    private function requestFactory(): PendingRequest
    {
        $baseUrl = config('sap.goods_issue.serial_selection.semantic_base_url');

        if (! is_string($baseUrl) || ! str_ends_with(rtrim($baseUrl, '/'), '/b1s/v1/sml.svc')) {
            throw new RuntimeException('SAP serial semantic view URL is not configured correctly.');
        }

        $request = Http::baseUrl(rtrim($baseUrl, '/').'/')
            ->acceptJson()
            ->connectTimeout(config('sap.connect_timeout_seconds'))
            ->timeout(config('sap.timeout_seconds'));

        return config('sap.verify_tls') ? $request : $request->withoutVerifying();
    }
}
