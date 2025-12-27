<?php

namespace App\Providers;

use App\Services\Queues\RabbitMQAdapter;
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
    }

    public function boot(): void
    {
        // Публикуем конфигурацию
        $this->publishes([
            __DIR__ . '/../../config/queue.php' => config_path('queue.php'),
        ], 'rabbitmq-config');
    }
}
