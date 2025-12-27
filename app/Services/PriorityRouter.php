<?php

namespace App\Services;

use App\DTO\EventData;
use App\Services\Queues\QueueAdapterInterface;

/**
 * Определяет приоритет сообщения
 *
 * В зависимости от приоритета, использует RabbitMQ или Redis очередь
 */
class PriorityRouter
{
    private const HIGH_PRIORITY_EVENTS = [
        'purchase',
        'subscription',
        'payment',
        'refund',
        'credit_card_added',
    ];

    // покупки свыше этой суммы идут в higher priority queue
    private const HIGH_PURCHASE_THRESHOLD = 100;

    private const PRIORITY_THRESHOLD = 8;

    public function __construct(
        private QueueAdapterInterface $highPriorityAdapter,
        private QueueAdapterInterface $normalPriorityAdapter,
        private MetricsService $metrics
    ) {}

    public function route(EventData $event): QueueAdapterInterface
    {
        $startTime = microtime(true);

        $isHighPriority = $this->isHighPriority($event);
        $adapter = $isHighPriority ? $this->highPriorityAdapter : $this->normalPriorityAdapter;

        $duration = microtime(true) - $startTime;
        $this->metrics->histogram('priority_router_duration_seconds', $duration, ['component' => 'router']);

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
     * Определение приоритетности события
     *
     * @param EventData $event
     * @return bool
     */
    private function isHighPriority(EventData $event): bool
    {
        // Проверка по типу события
        if (in_array($event->eventType, self::HIGH_PRIORITY_EVENTS, true)) {
            return true;
        }

        // Проверка по explicit priority
        if ($event->priority >= self::PRIORITY_THRESHOLD) {
            return true;
        }

        // Проверка по payload (например, большие суммы)
        if ($event->eventType === 'purchase' && isset($event->payload['amount'])) {
            return $event->payload['amount'] >= self::HIGH_PURCHASE_THRESHOLD;
        }

        return false;
    }
}
