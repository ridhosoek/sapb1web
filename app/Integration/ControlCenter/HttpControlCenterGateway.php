<?php

namespace App\Integration\ControlCenter;

use App\Integration\ControlCenter\Contracts\ControlCenterGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpControlCenterGateway implements ControlCenterGateway
{
    public function getPools(): array
    {
        return $this->collection($this->request()->get(config('control_center.endpoints.pools'))->throw()->json());
    }

    public function getDepartments(): array
    {
        return $this->collection($this->request()->get(config('control_center.endpoints.departments'))->throw()->json());
    }

    public function getVehiclesByPool(string $poolExternalId): array
    {
        return $this->collection($this->request()->get(
            config('control_center.endpoints.vehicles'),
            ['pool_id' => $poolExternalId],
        )->throw()->json());
    }

    public function getVehicle(string $vehicleExternalId): ?array
    {
        $payload = $this->request()->get(
            config('control_center.endpoints.vehicles').'/'.urlencode($vehicleExternalId),
        )->throw()->json();

        if (! is_array($payload)) {
            return null;
        }

        $vehicle = $payload['data'] ?? $payload;

        if (array_is_list($vehicle)) {
            return isset($vehicle[0]) && is_array($vehicle[0]) ? $vehicle[0] : null;
        }

        return $vehicle;
    }

    public function getUserOperationalContext(string $userExternalId): ?array
    {
        $endpoint = str_replace('{user}', urlencode($userExternalId), config('control_center.endpoints.user_context'));
        $payload = $this->request()->get($endpoint)->throw()->json();

        return is_array($payload) ? ($payload['data'] ?? $payload) : null;
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('control_center.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('Control Center URL is not configured.');
        }

        $request = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->connectTimeout(config('control_center.connect_timeout_seconds'))
            ->timeout(config('control_center.timeout_seconds'));

        if ($token = config('control_center.token')) {
            $request->withToken($token);
        }

        return config('control_center.verify_tls') ? $request : $request->withoutVerifying();
    }

    /** @return list<array<string, mixed>> */
    private function collection(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $items = $payload['data'] ?? $payload['value'] ?? $payload;

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }
}
