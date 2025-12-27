<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\EventData;
use App\Services\Queues\QueueAdapterInterface;
use App\Services\Queues\RabbitMQAdapter;
use App\Services\Queues\RedisStreamAdapter;

class EventQueueService
{
    // кролик
    private QueueAdapterInterface $highPriorityAdapter;
    // redis
    private QueueAdapterInterface $normalPriorityAdapter;

    public function __construct(
        private EventValidator $validator,
        private MetricsService $metrics,
        private PriorityRouter $priorityRouter,
    ) {
        $this->initializeAdapters();
    }

    /**
     * Инициализация адаптеров
     */
    private function initializeAdapters(): void
    {
        // Для высокоприоритетных задач - кролик
        $this->highPriorityAdapter = new RabbitMQAdapter(
            $this->metrics,
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

        // Для обычных - redis streams
        $this->normalPriorityAdapter = new RedisStreamAdapter(
            $this->metrics,
            [
                'stream_key' => config('queue.connections.redis_stream.queue'),
                'high_priority_stream' => 'events_high_priority',
                'consumer_group' => 'event_processors',
                'max_len' => 10000,
            ]
        );
    }

    /**
     * Роутим событие соответствующему адаптеру
     */
    public function push(EventData $event): string
    {
        // TODO: для метрик
        $startTime = microtime(true);

        $adapter = $this->priorityRouter->route($event);
        return $adapter->push($event);
    }

    public function startHighPriorityConsumer(callable $handler): void
    {
        $this->highPriorityAdapter->consume('events.high_priority', $handler);
    }

    public function startNormalPriorityConsumer(callable $handler): void
    {
        $this->normalPriorityAdapter->consume('events_stream', $handler);
    }

    public function getQueueStats(): array
    {
        return [
            'rabbitmq_high' => $this->highPriorityAdapter->getQueueStats('events.high_priority'),
            'rabbitmq_normal' => $this->highPriorityAdapter->getQueueStats('events.normal'),
            'redis_stream' => $this->normalPriorityAdapter->getQueueStats('events_stream'),
            'redis_high_stream' => $this->normalPriorityAdapter->getQueueStats('events_high_priority'),
        ];
    }
}
