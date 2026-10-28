<?php

return [
    'base_url' => env('CONTROL_CENTER_URL'),
    'token' => env('CONTROL_CENTER_TOKEN'),
    'connect_timeout_seconds' => (int) env('CONTROL_CENTER_CONNECT_TIMEOUT_SECONDS', 3),
    'timeout_seconds' => (int) env('CONTROL_CENTER_TIMEOUT_SECONDS', 15),
    'verify_tls' => env('CONTROL_CENTER_VERIFY_TLS', true),
    'endpoints' => [
        'pools' => env('CONTROL_CENTER_POOLS_ENDPOINT', '/api/pools'),
        'departments' => env('CONTROL_CENTER_DEPARTMENTS_ENDPOINT', '/api/departments'),
        'vehicles' => env('CONTROL_CENTER_VEHICLES_ENDPOINT', '/api/vehicles'),
        'user_context' => env('CONTROL_CENTER_USER_CONTEXT_ENDPOINT', '/api/users/{user}/operational-context'),
    ],
];
