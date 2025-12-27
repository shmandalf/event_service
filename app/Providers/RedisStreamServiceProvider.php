<?php

namespace App\Providers;

use App\Services\Queues\RedisStreamAdapter;
use Illuminate\Support\ServiceProvider;

class RedisStreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('queue.adapter.redis', function ($app) {
            return new \App\Services\Queues\RedisStreamAdapter(
                $app->make(\App\Services\MetricsService::class),
                [
                    'stream_key' => config('queue.connections.redis_stream.queue'),
                    'high_priority_stream' => 'events_high_priority',
                    'consumer_group' => 'event_processors',
                    'max_len' => 10000,
                ]
            );
        });
        $this->app->alias('queue.adapter.redis', RedisStreamAdapter::class);
    }

    public function boot(): void {}
}
