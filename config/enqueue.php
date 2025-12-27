<?php

return [
    'default' => [
        'transport' => [
            'dsn' => env('ENQUEUE_DSN', 'amqp://admin:admin123@rabbitmq:5672/%2F'),
        ],
        'client' => [
            'router_topic' => 'router',
            'router_queue' => 'default',
            'default_queue' => 'default',
        ],
    ],

    'rabbitmq' => [
        'transport' => [
            'dsn' => env('ENQUEUE_DSN', 'amqp://admin:admin123@rabbitmq:5672/%2F'),
            'connection' => [
                'read_timeout' => 3.,
                'write_timeout' => 3.,
                'heartbeat' => 60,
                'persisted' => true,
            ],
        ],
    ],
];
