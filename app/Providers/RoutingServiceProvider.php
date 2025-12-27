<?php

namespace App\Providers;

use App\Services\PriorityRouter;
use Illuminate\Support\ServiceProvider;

class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PriorityRouter::class, function ($app) {
            return new PriorityRouter(
                $app->make('queue.adapter.rabbitmq'),
                $app->make('queue.adapter.redis'),
                $app->make(\App\Services\MetricsService::class)
            );
        });
    }

    public function boot(): void {}
}
