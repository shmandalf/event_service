<?php

return [
    'namespace' => env('PROMETHEUS_NAMESPACE', 'event_service'),

    'storage_adapter' => 'redis',

    'redis' => [
        'host' => env('REDIS_HOST', 'redis'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'),
        'database' => env('REDIS_DATABASE', 0),
        'timeout' => 0.1,
        'read_timeout' => 10,
        'persistent_connections' => false,
        'prefix' => 'prometheus:',
    ],

    // Автоматически регистрируемые метрики
    'default_metrics' => [
        'php_info' => true,
        'http_requests' => true,
        'memory_usage' => true,
    ],

    // Buckets для гистограмм
    'buckets' => [
        'http_request_duration' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0],
        'event_processing_duration' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],
    ],
];
