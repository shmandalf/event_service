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
    private const STREAM_KEY = 'events_stream';
    private const HIGH_PRIORITY_STREAM = 'events_high_priority';
    private const CONSUMER_GROUP = 'event_processors';
    private const CONSUMER_NAME = 'worker_';
    private const BATCH_SIZE = 100;
    private const MAX_RETRIES = 3;

    private string $consumerId;

    public function __construct(
        private EventValidator $validator,
        private MetricsService $metrics,
        private CircuitBreaker $circuitBreaker,
        private array $handlers = []
    ) {
        $this->consumerId = self::CONSUMER_NAME . gethostname() . '_' . getmypid();
        $this->registerHandlers();
    }

    /**
     * Обработка батча событий
     */
    public function processBatch(int $batchSize = self::BATCH_SIZE): int
    {
        $startTime = microtime(true);
        $processed = 0;

        try {
            // 1. Сначала проверяем high priority stream
            $processed += $this->processStream(self::HIGH_PRIORITY_STREAM, $batchSize - $processed);

            // 2. Если есть место в батче, обрабатываем обычные события
            if ($processed < $batchSize) {
                $processed += $this->processStream(self::STREAM_KEY, $batchSize - $processed);
            }

            // 3. Пытаемся забрать "зависшие" сообщения
            if ($processed < $batchSize / 2) {
                $processed += $this->claimPendingMessages($batchSize - $processed);
            }
        } catch (Throwable $e) {
            $this->metrics->increment('stream_processor_errors_total');
            Log::error('Batch processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Circuit breaker для проблемных потоков
            $this->circuitBreaker->recordFailure('stream_processing');

            throw $e;
        }

        // Метрики
        $duration = microtime(true) - $startTime;
        $this->metrics->histogram('batch_processing_duration_seconds', $duration, [
            'batch_size' => (string) $processed,
        ]);

        $this->metrics->increment('events_processed_total', [
            'stream_type' => $processed > 0 ? 'mixed' : 'empty',
        ]);

        if ($processed > 0) {
            $this->circuitBreaker->recordSuccess('stream_processing');
        }

        return $processed;
    }

    /**
     * Обработка одного стрима
     *
     * @TODO - вынести в Redis адаптер
     */
    private function processStream(string $streamKey, int $limit): int
    {
        $processed = 0;

        $messages = Redis::xreadgroup(
            self::CONSUMER_GROUP,
            $this->consumerId,
            [$streamKey => '>'],
            $limit,
            1000 // timeout в миллисекундах
        );

        if (empty($messages)) {
            return 0;
        }

        foreach ($messages as $stream => $streamMessages) {
            foreach ($streamMessages as $messageId => $message) {
                try {
                    $this->processMessage($messageId, $message, $stream);
                    $processed++;

                    // ACK сообщения
                    Redis::xack($stream, self::CONSUMER_GROUP, [$messageId]);
                } catch (Throwable $e) {
                    $this->handleProcessingError($messageId, $message, $stream, $e);
                }

                // Прерываем если достигли лимита
                if ($processed >= $limit) {
                    break 2;
                }
            }
        }

        return $processed;
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
                'source' => 'direct', // rabbitmq или redis
            ]);

            $this->metrics->increment('event_processed_total', [
                'type' => $event->eventType,
                'status' => 'success',
                'source' => 'direct',
            ]);

            Log::debug('Event processed successfully', [
                'event_id' => $event->id,
                'type' => $event->eventType,
                'processing_time_ms' => round($duration * 1000, 2),
                'source' => 'direct',
            ]);
        } catch (Throwable $e) {
            $this->metrics->increment('event_processing_errors_total', [
                'event_type' => $event->eventType,
                'error_type' => get_class($e),
                'source' => 'direct',
            ]);

            Log::error('Failed to process event', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'source' => 'direct',
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
     * Забрать зависшие сообщения
     */
    private function claimPendingMessages(int $limit): int
    {
        $claimed = 0;

        foreach ([self::HIGH_PRIORITY_STREAM, self::STREAM_KEY] as $stream) {
            // Получаем зависшие сообщения (старше 30 секунд)
            $pending = Redis::connection()->client()->rawCommand(
                'XPENDING',
                $stream,
                self::CONSUMER_GROUP,
                'IDLE',
                30000,  // 30 секунд в миллисекундах
                '-',
                '+',
                $limit
            );

            /*
            $pending = Redis::xpending(
                $stream,
                self::CONSUMER_GROUP,
                '-', // start id
                '+', // end id
                $limit,
                ['IDLE' => 30000] // 30 секунд
            );
            */

            if (empty($pending)) {
                continue;
            }

            $messageIds = array_column($pending, 0);

            // Пытаемся забрать себе
            $claimedMessages = Redis::xclaim(
                $stream,
                self::CONSUMER_GROUP,
                $this->consumerId,
                60000, // 60 секунд timeout
                $messageIds,
                [
                    'JUSTID' => true,
                    'FORCE' => true,
                ]
            );

            foreach ($claimedMessages as $messageId) {
                try {
                    // Получаем полное сообщение
                    $range = Redis::xrange($stream, $messageId, $messageId);
                    if (!empty($range[$messageId])) {
                        $this->processMessage($messageId, $range[$messageId], $stream);
                        Redis::xack($stream, self::CONSUMER_GROUP, [$messageId]);
                        $claimed++;
                    }
                } catch (Throwable $e) {
                    Log::warning('Failed to claim message', [
                        'message_id' => $messageId,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($claimed >= $limit) {
                    break 2;
                }
            }
        }

        if ($claimed > 0) {
            $this->metrics->increment('events_claimed_total', ['count' => (string) $claimed]);
        }

        return $claimed;
    }

    /**
     * Обработка ошибок при обработке сообщения
     */
    private function handleProcessingError(
        string $messageId,
        array $message,
        string $stream,
        Throwable $e
    ): void {
        $attempts = (int) ($message['attempts'] ?? 0) + 1;

        if ($attempts >= self::MAX_RETRIES) {
            // Отправляем в dead letter queue
            $this->sendToDeadLetterQueue($messageId, $message, $stream, $e);
            Redis::xack($stream, self::CONSUMER_GROUP, [$messageId]);

            $this->metrics->increment('events_failed_total', [
                'reason' => 'max_retries_exceeded',
                'error_type' => get_class($e),
            ]);

            Log::error('Event moved to DLQ after max retries', [
                'message_id' => $messageId,
                'attempts' => $attempts,
                'error' => $e->getMessage(),
                'stream' => $stream,
            ]);
        } else {
            // Возвращаем в поток с увеличенным счетчиком попыток
            $message['attempts'] = $attempts;
            $message['last_error'] = $e->getMessage();
            $message['last_error_at'] = now()->toISOString();

            Redis::xadd($stream, '*', $message, 10000, true);
            Redis::xack($stream, self::CONSUMER_GROUP, [$messageId]);

            $this->metrics->increment('events_retried_total');

            Log::warning('Event retried', [
                'message_id' => $messageId,
                'attempt' => $attempts,
                'error' => $e->getMessage(),
                'stream' => $stream,
            ]);
        }
    }

    /**
     * Отправка в Dead Letter Queue
     */
    private function sendToDeadLetterQueue(
        string $messageId,
        array $message,
        string $stream,
        Throwable $e
    ): void {
        $dlqMessage = [
            'original_message_id' => $messageId,
            'original_stream' => $stream,
            'event' => $message['event'] ?? '',
            'error' => $e->getMessage(),
            'error_trace' => $e->getTraceAsString(),
            'failed_at' => now()->toISOString(),
            'attempts' => $message['attempts'] ?? 0,
        ];

        Redis::xadd('events_dlq', '*', $dlqMessage, 10000, true);
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
