<?php

namespace App\Services\Handlers;

use App\DTO\EventData;
use App\Services\EventStreamProcessor;
use Illuminate\Support\Facades\Log;

class RabbitMQEventHandler
{
    public function __construct(
        private EventStreamProcessor $processor
    ) {}

    /**
     * Обработчик для RabbitMQ сообщений
     */
    public function handle(EventData $event): void
    {
        $startTime = microtime(true);

        try {
            // Делегируем обработку в EventStreamProcessor
            $this->processor->processEvent($event);

            $duration = microtime(true) - $startTime;

            Log::info('RabbitMQ event handled', [
                'event_id' => $event->id,
                'type' => $event->eventType,
                'processing_time_ms' => round($duration * 1000, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle RabbitMQ event', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
