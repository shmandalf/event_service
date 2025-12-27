<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\RabbitMQServiceProvider::class,
    App\Providers\RedisStreamServiceProvider::class,
    App\Providers\RoutingServiceProvider::class,
    // Enqueue\LaravelQueue\EnqueueServiceProvider::class,
];
