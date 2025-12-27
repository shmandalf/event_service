<?php

namespace App\Providers;

use App\Services\Queues\RabbitMQAdapter;
use App\Services\Queues\QueueAdapterInterface;
use App\Services\PriorityRouter;
use Illuminate\Support\ServiceProvider;

class RabbitMQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('queue.adapter.rabbitmq', function ($app) {
            return new RabbitMQAdapter(
                $app->make(\App\Services\MetricsService::class),
                [
                    'host' => config('queue.connections.rabbitmq.host'),
                    'port' => config('queue.connections.rabbitmq.port'),
                    'user' => config('queue.connections.rabbitmq.user'),
                    'password' => config('queue.connections.rabbitmq.password'),
                    'vhost' => config('queue.connections.rabbitmq.vhost'),
                    'qos' => config('queue.connections.rabbitmq.options.qos', [
                        'prefetch_count' => 10,
                    ]),
                ]
            );
        });

        $this->app->alias('queue.adapter.rabbitmq', RabbitMQAdapter::class);

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

        $this->app->singleton(PriorityRouter::class, function ($app) {
            return new PriorityRouter(
                $app->make('queue.adapter.rabbitmq'),
                $app->make('queue.adapter.redis'),
                $app->make(\App\Services\MetricsService::class)
            );
        });
    }

    public function boot(): void
    {
        // Публикуем конфигурацию
        $this->publishes([
            __DIR__ . '/../../config/queue.php' => config_path('queue.php'),
        ], 'rabbitmq-config');
    }
}
