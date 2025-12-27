<?php

namespace App\Services;

use App\DTO\EventData;
use App\Services\Queues\QueueAdapterInterface;

class PriorityRouter
{
    private const HIGH_PRIORITY_EVENTS = [
        'purchase',
        'subscription',
        'payment',
        'refund',
        'credit_card_added',
    ];

    private const PRIORITY_THRESHOLD = 8;

    public function __construct(
        private QueueAdapterInterface $highPriorityAdapter,
        private QueueAdapterInterface $normalPriorityAdapter,
        private MetricsService $metrics
    ) {}

    /**
     * Получить адаптер для маршрутизации события
     */
    public function route(EventData $event): QueueAdapterInterface
    {
        $startTime = microtime(true);

        $isHighPriority = $this->isHighPriority($event);
        $adapter = $isHighPriority ? $this->highPriorityAdapter : $this->normalPriorityAdapter;

        $duration = microtime(true) - $startTime;
        $this->metrics->histogram('priority_router_duration_seconds', $duration, [
            'priority' => $isHighPriority ? 'high' : 'normal'
        ]);

        $this->metrics->increment('events_routed_total', [
            'priority' => $isHighPriority ? 'high' : 'normal',
            'event_type' => $event->eventType,
        ]);

        \Log::debug('Event routed', [
            'event_id' => $event->id,
            'type' => $event->eventType,
            'priority' => $event->priority,
            'route' => $isHighPriority ? 'high' : 'normal',
            'routing_time_ms' => round($duration * 1000, 2),
        ]);

        return $adapter;
    }

    /**
     * Получить все адаптеры
     */
    public function getAdapters(): array
    {
        return [
            'high' => $this->highPriorityAdapter,
            'normal' => $this->normalPriorityAdapter,
        ];
    }

    /**
     * Получить адаптер по типу
     */
    public function getAdapter(string $type): ?QueueAdapterInterface
    {
        return match ($type) {
            'high', 'rabbitmq' => $this->highPriorityAdapter,
            'normal', 'redis' => $this->normalPriorityAdapter,
            default => null,
        };
    }

    /**
     * Получить тип адаптера (rabbitmq или redis)
     */
    public function getAdapterType(QueueAdapterInterface $adapter): string
    {
        if ($adapter instanceof \App\Services\Queues\RabbitMQAdapter) {
            return 'rabbitmq';
        }

        if ($adapter instanceof \App\Services\Queues\RedisStreamAdapter) {
            return 'redis';
        }

        return 'unknown';
    }

    /**
     * Проверить, является ли событие high priority
     */
    private function isHighPriority(EventData $event): bool
    {
        // 1. Проверка по типу события
        if (in_array($event->eventType, self::HIGH_PRIORITY_EVENTS, true)) {
            return true;
        }

        // 2. Проверка по explicit priority
        if ($event->priority >= self::PRIORITY_THRESHOLD) {
            return true;
        }

        // 3. Проверка по payload (например, большие суммы)
        if ($event->eventType === 'purchase' && isset($event->payload['amount'])) {
            return $event->payload['amount'] >= 100; // Покупки от $100 - high priority
        }

        return false;
    }

    /**
     * Получить конфигурацию маршрутизации
     */
    public function getRoutingConfig(): array
    {
        return [
            'high_priority_events' => self::HIGH_PRIORITY_EVENTS,
            'priority_threshold' => self::PRIORITY_THRESHOLD,
            'high_purchase_threshold' => 100,
        ];
    }

    /**
     * Получить статистику маршрутизации
     */
    public function getStats(): array
    {
        $adapters = $this->getAdapters();

        $stats = [
            'config' => $this->getRoutingConfig(),
            'adapters' => [],
        ];

        foreach ($adapters as $name => $adapter) {
            $stats['adapters'][$name] = [
                'type' => $this->getAdapterType($adapter),
                'class' => get_class($adapter),
            ];
        }

        return $stats;
    }
}
