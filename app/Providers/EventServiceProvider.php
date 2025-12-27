<?php

namespace App\Providers;

use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\EventStreamProcessor;
use App\Services\EventValidator;
use App\Services\MetricsService;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventStreamProcessor::class, function ($app) {
            return new EventStreamProcessor(
                $app->make(EventValidator::class),
                $app->make(MetricsService::class),
                new CircuitBreaker('stream_processing'),
                $this->loadHandlers()
            );
        });

        $this->app->singleton('circuit_breaker.stream', function () {
            return new CircuitBreaker('stream_processing');
        });

        $this->app->singleton('circuit_breaker.external', function () {
            return new CircuitBreaker('external_api');
        });
    }

    private function loadHandlers(): array
    {
        $handlers = [];
        $config = config('event_handlers', []);

        foreach ($config as $eventType => $handlerClasses) {
            foreach ($handlerClasses as $handlerClass) {
                if (class_exists($handlerClass)) {
                    $handlers[$eventType][] = $this->app->make($handlerClass);
                }
            }
        }

        return $handlers;
    }

    public function boot(): void
    {
        // Публикация конфигураций
        $this->publishes([
            __DIR__ . '/../../config/event_handlers.php' => config_path('event_handlers.php'),
            __DIR__ . '/../../config/circuit_breaker.php' => config_path('circuit_breaker.php'),
        ], 'event-service');
    }
}
