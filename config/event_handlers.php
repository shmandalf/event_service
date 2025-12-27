<?php

return [
    'purchase' => [
        \App\Services\Handlers\PurchaseEventHandler::class,
        // \App\Services\Handlers\AnalyticsEventHandler::class,
    ],

    'click' => [
        // \App\Services\Handlers\ClickEventHandler::class,
    ],

    'view' => [
        // \App\Services\Handlers\ViewEventHandler::class,
    ],

    'login' => [
        // \App\Services\Handlers\AuthEventHandler::class,
        // \App\Services\Handlers\SecurityEventHandler::class,
    ],
];
