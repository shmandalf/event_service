<?php

namespace App\Services;

use App\DTO\EventData;
use App\Models\Event;
use App\Services\CircuitBreaker\CircuitBreaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class EventStreamProcessor
{
    private const CONSUMER_NAME_PREFIX = 'worker_';

    // not used
    private string $consumerId;

    public function __construct(
        private EventValidator $validator,
        private MetricsService $metrics,
        private CircuitBreaker $circuitBreaker,
        private array $handlers = []
    ) {
        $this->consumerId = self::CONSUMER_NAME_PREFIX . gethostname() . '_' . getmypid();
        $this->registerHandlers();
    }

    /**
     * Обработка одного события (публичный метод для внешних вызовов)
     */
    public function processEvent(EventData $event): void
    {
        $startTime = microtime(true);

        try {
            // 1. Проверяем идемпотентность
            if ($event->idempotencyKey && $this->isAlreadyProcessed($event->idempotencyKey)) {
                Log::debug('Event already processed (idempotency)', [
                    'event_id' => $event->id,
                    'idempotency_key' => $event->idempotencyKey,
                ]);
                return;
            }

            // 2. Сохраняем в базу данных в транзакции
            DB::transaction(function () use ($event) {
                $eventModel = Event::create([
                    'id' => $event->id,
                    'user_id' => $event->userId,
                    'event_type' => $event->eventType,
                    'timestamp' => $event->timestamp,
                    'priority' => $event->priority,
                    'payload' => $event->payload,
                    'metadata' => $event->metadata ?? [],
                    'status' => 'processing',
                    'idempotency_key' => $event->idempotencyKey,
                ]);

                // 3. Вызываем обработчики для этого типа события
                $this->dispatchToHandlers($event);

                // 4. Помечаем как обработанное
                $eventModel->markAsProcessed();

                // 5. Сохраняем идемпотентный ключ
                if ($event->idempotencyKey) {
                    $this->saveIdempotencyKey($event->idempotencyKey, $event->id);
                }
            });

            // 6. Метрики
            $duration = microtime(true) - $startTime;
            $this->metrics->histogram('event_processing_duration_seconds', $duration, [
                'event_type' => $event->eventType,
                'priority' => (string) $event->priority,
                'source' => $event->source ?? 'unknown',
            ]);

            $this->metrics->increment('event_processed_total', [
                'type' => $event->eventType,
                'status' => 'success',
                'source' => $event->source ?? 'unknown',
            ]);

            Log::debug('Event processed successfully', [
                'event_id' => $event->id,
                'type' => $event->eventType,
                'processing_time_ms' => round($duration * 1000, 2),
                'source' => $event->source ?? 'unknown',
            ]);
        } catch (Throwable $e) {
            $this->metrics->increment('event_processing_errors_total', [
                'event_type' => $event->eventType,
                'error_type' => get_class($e),
                'source' => $event->source ?? 'unknown',
            ]);

            Log::error('Failed to process event', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'source' => $event->source ?? 'unknown',
            ]);

            throw $e;
        }
    }

    /**
     * Обработка одного сообщения
     */
    private function processMessage(string $messageId, array $message, string $stream): void
    {
        $startTime = microtime(true);

        // 1. Декодируем событие из JSON
        $eventData = json_decode($message['event'], true, 512, JSON_THROW_ON_ERROR);

        // 2. Создаем DTO
        $eventDto = EventData::fromArray($eventData);

        // 3. Проверяем идемпотентность
        if ($eventDto->idempotencyKey && $this->isAlreadyProcessed($eventDto->idempotencyKey)) {
            Log::debug('Event already processed (idempotency)', [
                'event_id' => $eventDto->id,
                'idempotency_key' => $eventDto->idempotencyKey,
            ]);
            return;
        }

        // 4. Сохраняем в базу данных в транзакции
        DB::transaction(function () use ($eventDto, $messageId, $stream) {
            $event = Event::create([
                'id' => $eventDto->id,
                'user_id' => $eventDto->userId,
                'event_type' => $eventDto->eventType,
                'timestamp' => $eventDto->timestamp,
                'priority' => $eventDto->priority,
                'payload' => $eventDto->payload,
                'metadata' => $eventDto->metadata ?? [],
                'status' => 'processing',
                'idempotency_key' => $eventDto->idempotencyKey,
            ]);

            // 5. Вызываем обработчики для этого типа события
            $this->dispatchToHandlers($eventDto);

            // 6. Помечаем как обработанное
            $event->markAsProcessed();

            // 7. Сохраняем идемпотентный ключ
            if ($eventDto->idempotencyKey) {
                $this->saveIdempotencyKey($eventDto->idempotencyKey, $eventDto->id);
            }
        });

        // 8. Метрики
        $duration = microtime(true) - $startTime;
        $this->metrics->histogram('message_processing_duration_seconds', $duration, [
            'event_type' => $eventDto->eventType,
            'priority' => (string) $eventDto->priority,
            'stream' => $stream,
        ]);

        $this->metrics->increment('event_processed_total', [
            'type' => $eventDto->eventType,
            'status' => 'success',
        ]);

        Log::debug('Event processed successfully', [
            'event_id' => $eventDto->id,
            'type' => $eventDto->eventType,
            'message_id' => $messageId,
            'processing_time_ms' => round($duration * 1000, 2),
        ]);
    }

    /**
     * Проверка идемпотентности
     */
    private function isAlreadyProcessed(string $idempotencyKey): bool
    {
        return Redis::exists("idempotency:$idempotencyKey") === 1;
    }

    /**
     * Сохранение идемпотентного ключа
     */
    private function saveIdempotencyKey(string $key, string $eventId, int $ttl = 86400): void
    {
        Redis::setex("idempotency:$key", $ttl, $eventId);
    }

    /**
     * Вызов обработчиков события
     */
    private function dispatchToHandlers(EventData $event): void
    {
        $handlers = $this->handlers[$event->eventType] ?? [];

        foreach ($handlers as $handler) {
            try {
                $start = microtime(true);

                $handler->handle($event);

                $duration = microtime(true) - $start;
                $this->metrics->histogram('handler_duration_seconds', $duration, [
                    'handler' => get_class($handler),
                    'event_type' => $event->eventType,
                ]);
            } catch (Throwable $e) {
                $this->metrics->increment('handler_errors_total', [
                    'handler' => get_class($handler),
                    'event_type' => $event->eventType,
                ]);

                Log::error('Handler failed', [
                    'handler' => get_class($handler),
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);

                // Продолжаем с другими обработчиками
                continue;
            }
        }
    }

    /**
     * Регистрация обработчиков
     */
    private function registerHandlers(): void
    {
        // Пример: можно загружать из конфигурации
        $handlers = config('event_handlers', []);

        foreach ($handlers as $eventType => $handlerClasses) {
            foreach ($handlerClasses as $handlerClass) {
                if (class_exists($handlerClass)) {
                    $this->handlers[$eventType][] = app($handlerClass);
                }
            }
        }
    }
}
