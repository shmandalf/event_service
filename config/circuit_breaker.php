<?php
return [
    'stream_processing' => [
        'failure_threshold' => 10,
        'success_threshold' => 5,
        'timeout' => 300, // 5 минут
        'half_open_timeout' => 60,
    ],

    'external_api' => [
        'failure_threshold' => 3,
        'success_threshold' => 2,
        'timeout' => 60,
        'half_open_timeout' => 30,
    ],
];
