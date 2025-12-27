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
            return $event->payload['amount'] >= 1000; // Покупки от $1000 - high priority
        }

        return false;
    }

    public function getRoutingConfig(): array
    {
        return [
            'high_priority_events' => self::HIGH_PRIORITY_EVENTS,
            'priority_threshold' => self::PRIORITY_THRESHOLD,
            'high_purchase_threshold' => 100,
        ];
    }
}
