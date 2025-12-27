<?php

namespace App\Providers;

use App\Services\DeadLetterQueueManager;
use App\Services\Queues\RabbitMQAdapter;
use App\Services\RetryManager;
use Illuminate\Support\ServiceProvider;

class RabbitMQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RetryManager::class, function ($app) {
            return new RetryManager($app->make(\App\Services\MetricsService::class));
        });

        $this->app->singleton(DeadLetterQueueManager::class, function ($app) {
            return new DeadLetterQueueManager(
                $app->make(\App\Services\MetricsService::class),
                $app->make(RetryManager::class),
                [
                    'host' => config('queue.connections.rabbitmq.host'),
                    'port' => config('queue.connections.rabbitmq.port'),
                    'user' => config('queue.connections.rabbitmq.user'),
                    'password' => config('queue.connections.rabbitmq.password'),
                    'vhost' => config('queue.connections.rabbitmq.vhost'),
                ]
            );
        });

        $this->app->singleton('queue.adapter.rabbitmq', function ($app) {
            return new \App\Services\Queues\RabbitMQAdapter(
                $app->make(\App\Services\MetricsService::class),
                $app->make(RetryManager::class),
                $app->make(DeadLetterQueueManager::class),
                [
                    'host' => config('queue.connections.rabbitmq.host'),
                    'port' => config('queue.connections.rabbitmq.port'),
                    'user' => config('queue.connections.rabbitmq.user'),
                    'password' => config('queue.connections.rabbitmq.password'),
                    'vhost' => config('queue.connections.rabbitmq.vhost'),
                    'qos' => [
                        'prefetch_count' => 10,
                    ],
                ]
            );
        });
        $this->app->alias('queue.adapter.rabbitmq', RabbitMQAdapter::class);
    }

    public function boot(): void
    {
        // Публикуем конфигурацию
        $this->publishes([
            __DIR__ . '/../../config/queue.php' => config_path('queue.php'),
        ], 'rabbitmq-config');
    }
}
