<?php

namespace App\Services;

use App\DTO\EventData;
use App\Services\CircuitBreaker\QueueCircuitBreaker;
use Illuminate\Support\Facades\Log;

class EventQueueService
{
    private QueueCircuitBreaker $rabbitmqCircuitBreaker;
    private QueueCircuitBreaker $redisCircuitBreaker;

    public function __construct(
        private EventValidator $validator,
        private MetricsService $metrics,
        private PriorityRouter $priorityRouter
    ) {
        $this->rabbitmqCircuitBreaker = new QueueCircuitBreaker('rabbitmq');
        $this->redisCircuitBreaker = new QueueCircuitBreaker('redis');
    }

    public function push(EventData $event): string
    {
        $startTime = microtime(true);

        try {
            // Определяем приоритетный адаптер
            $primaryAdapter = $this->priorityRouter->route($event);
            $adapterType = $this->priorityRouter->getAdapterType($primaryAdapter);

            // Проверяем circuit breaker для основного адаптера
            $circuitBreaker = $this->getCircuitBreaker($adapterType);

            if (!$circuitBreaker->isAvailable()) {
                // Failover на другой адаптер
                $fallbackAdapter = $this->getFallbackAdapter($adapterType);

                if ($fallbackAdapter) {
                    $fallbackType = $this->priorityRouter->getAdapterType($fallbackAdapter);

                    $this->metrics->increment('queue_failover_total', [
                        'from' => $adapterType,
                        'to' => $fallbackType,
                        'event_type' => $event->eventType,
                    ]);

                    Log::warning('Queue failover triggered', [
                        'event_id' => $event->id,
                        'from' => $adapterType,
                        'to' => $fallbackType,
                        'reason' => 'circuit_breaker_open',
                    ]);

                    $adapter = $fallbackAdapter;
                    $adapterType = $fallbackType;
                    $circuitBreaker = $this->getCircuitBreaker($adapterType);
                } else {
                    // Нет fallback адаптера, используем основной
                    $adapter = $primaryAdapter;
                    Log::warning('No fallback adapter available, using primary despite circuit breaker', [
                        'event_id' => $event->id,
                        'adapter' => $adapterType,
                    ]);
                }
            } else {
                $adapter = $primaryAdapter;
            }

            // Отправляем в очередь
            $messageId = $adapter->push($event, $event->priority);

            // Записываем успех в circuit breaker
            $circuitBreaker->recordSuccess();

            // Метрики
            $duration = microtime(true) - $startTime;
            $this->recordPushMetrics($duration, $adapterType, $event, 'success');

            Log::info('Event queued', [
                'event_id' => $event->id,
                'adapter' => $adapterType,
                'priority' => $event->priority,
                'queue_time_ms' => round($duration * 1000, 2),
                'failover' => isset($fallbackAdapter) && $adapter === $fallbackAdapter,
            ]);

            return $messageId;
        } catch (\Exception $e) {
            // Записываем неудачу в circuit breaker
            if (isset($adapterType)) {
                $circuitBreaker = $this->getCircuitBreaker($adapterType);
                $circuitBreaker->recordFailure(get_class($e));
            }

            $duration = microtime(true) - $startTime;
            $adapterType = $adapterType ?? 'unknown';
            $this->recordPushMetrics($duration, $adapterType, $event, 'error', $e);

            // Пробуем emergency fallback
            return $this->emergencyFallback($event, $e);
        }
    }

    /**
     * Получить circuit breaker по типу адаптера
     */
    private function getCircuitBreaker(string $adapterType): QueueCircuitBreaker
    {
        return match ($adapterType) {
            'rabbitmq' => $this->rabbitmqCircuitBreaker,
            'redis' => $this->redisCircuitBreaker,
            default => $this->rabbitmqCircuitBreaker, // fallback
        };
    }

    /**
     * Получить fallback адаптер
     */
    private function getFallbackAdapter(string $primaryType): ?\App\Services\Queues\QueueAdapterInterface
    {
        $adapters = $this->priorityRouter->getAdapters();

        // Если primary - rabbitmq, возвращаем redis
        if ($primaryType === 'rabbitmq') {
            foreach ($adapters as $adapter) {
                if ($this->priorityRouter->getAdapterType($adapter) === 'redis') {
                    return $adapter;
                }
            }
        }

        // Если primary - redis, возвращаем rabbitmq
        if ($primaryType === 'redis') {
            foreach ($adapters as $adapter) {
                if ($this->priorityRouter->getAdapterType($adapter) === 'rabbitmq') {
                    return $adapter;
                }
            }
        }

        return null;
    }

    /**
     * Emergency fallback: сохраняем в базу напрямую
     */
    private function emergencyFallback(EventData $event, \Exception $originalError): string
    {
        $this->metrics->increment('queue_emergency_fallback_total');

        try {
            // Сохраняем напрямую в базу как failed
            $eventModel = \App\Models\Event::create([
                'id' => $event->id,
                'user_id' => $event->userId,
                'event_type' => $event->eventType,
                'timestamp' => $event->timestamp,
                'priority' => $event->priority,
                'payload' => $event->payload,
                'metadata' => $event->metadata ?? [],
                'status' => 'failed',
                'idempotency_key' => $event->idempotencyKey,
                'last_error' => 'Emergency fallback: ' . $originalError->getMessage(),
            ]);

            Log::critical('Emergency fallback used', [
                'event_id' => $event->id,
                'error' => $originalError->getMessage(),
                'saved_to_db' => true,
            ]);

            return $event->id;
        } catch (\Exception $e) {
            Log::emergency('Emergency fallback failed', [
                'event_id' => $event->id,
                'original_error' => $originalError->getMessage(),
                'fallback_error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Failed to queue event and emergency fallback also failed: ' .
                    $originalError->getMessage() . ' | ' . $e->getMessage()
            );
        }
    }

    /**
     * Записать метрики отправки
     */
    private function recordPushMetrics(
        float $duration,
        string $adapterType,
        EventData $event,
        string $status,
        ?\Exception $error = null
    ): void {
        $this->metrics->histogram('event_queue_push_duration_seconds', $duration, [
            'adapter' => $adapterType,
            'status' => $status,
            'priority' => (string) $event->priority,
        ]);

        $this->metrics->increment('events_queued_total', [
            'adapter' => $adapterType,
            'status' => $status,
            'event_type' => $event->eventType,
            'priority' => $event->isHighPriority() ? 'high' : 'normal',
        ]);

        if ($error) {
            $this->metrics->increment('event_queue_push_errors_total', [
                'adapter' => $adapterType,
                'error_type' => get_class($error),
            ]);
        }
    }

    /**
     * Получить статистику очередей
     */
    public function getQueueStats(): array
    {
        $stats = [];
        $adapters = $this->priorityRouter->getAdapters();

        foreach ($adapters as $name => $adapter) {
            $adapterType = $this->priorityRouter->getAdapterType($adapter);

            if ($adapterType === 'rabbitmq') {
                $stats['rabbitmq_high'] = $adapter->getQueueStats('events.high_priority');
                $stats['rabbitmq_normal'] = $adapter->getQueueStats('events.normal');
            } elseif ($adapterType === 'redis') {
                $stats['redis_stream'] = $adapter->getQueueStats('events_stream');
                $stats['redis_high_stream'] = $adapter->getQueueStats('events_high_priority');
            }
        }

        return $stats;
    }

    /**
     * Получить статистику circuit breakers
     */
    public function getCircuitBreakerStats(): array
    {
        return [
            'rabbitmq' => $this->rabbitmqCircuitBreaker->getErrorStats(),
            'redis' => $this->redisCircuitBreaker->getErrorStats(),
        ];
    }

    /**
     * Получить информацию о системе
     */
    public function getSystemInfo(): array
    {
        return [
            'queue_stats' => $this->getQueueStats(),
            'circuit_breaker_stats' => $this->getCircuitBreakerStats(),
            'router_config' => $this->priorityRouter->getRoutingConfig(),
            'adapters' => array_map(
                fn($adapter) => get_class($adapter),
                $this->priorityRouter->getAdapters()
            ),
        ];
    }
}
