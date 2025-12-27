<?php

namespace App\Services\Queues;

use App\DTO\EventData;
use App\Services\MetricsService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class RedisStreamAdapter implements QueueAdapterInterface
{
    private string $streamKey;
    private string $highPriorityStream;
    private string $consumerGroup;
    private int $maxLen;

    public function __construct(
        private MetricsService $metrics,
        private array $config
    ) {
        $this->streamKey = $config['stream_key'] ?? 'events_stream';
        $this->highPriorityStream = $config['high_priority_stream'] ?? 'events_high_priority';
        $this->consumerGroup = $config['consumer_group'] ?? 'event_processors';
        $this->maxLen = $config['max_len'] ?? 10000;

        $this->ensureConsumerGroup();
    }

    public function push(EventData $event, int $priority = 0): string
    {
        $startTime = microtime(true);

        /*
        $adapter = $this->priorityRouter->route($event);
        return $adapter->push($event);
        */

        try {
            $streamKey = $event->isHighPriority()
                ? $this->highPriorityStream
                : $this->streamKey;

            $messageId = Redis::xadd(
                $streamKey,
                '*',
                [
                    'event' => json_encode($event->toArray()),
                    'timestamp' => now()->getTimestamp(),
                    'attempts' => 0,
                ],
                $this->maxLen,
                true
            );

            // Инкремент метрик
            $this->metrics->increment('events_received_total', [
                'type' => $event->eventType,
                'priority' => $event->priority,
            ]);

            $this->metrics->histogram('queue_push_duration_seconds', microtime(true) - $startTime);

            Log::debug('Event queued', [
                'event_id' => $event->id,
                'message_id' => $messageId,
                'stream' => $streamKey,
            ]);

            return $messageId;
        } catch (\Exception $e) {
            $this->metrics->increment('queue_push_errors_total');
            Log::error('Failed to push event to queue', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            Log::error('Failed to queue event', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException('Failed to queue event: ' . $e->getMessage());
        }
    }

    public function getQueueStats(string $queue): array
    {
        $stats[$queue] = [
            'length' => Redis::xlen($queue),
            'pending' => Redis::xpending($queue, $this->consumerGroup)['pending'] ?? 0,
            'consumers' => count(Redis::xinfo('CONSUMERS', $queue, $this->consumerGroup)),
        ];

        $stats['dlq_length'] = Redis::xlen('events_dlq');

        return $stats;
    }

    public function consume(string $queue, callable $handler, int $timeout = 0): void {}

    public function disconnect(): void {}

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Создание consumer group если не существует
     */
    private function ensureConsumerGroup(): void
    {
        try {
            Redis::xgroup('CREATE', $this->streamKey, $this->consumerGroup, '0', true);
        } catch (\Exception $e) {
            // Group уже существует - это нормально
        }

        try {
            Redis::xgroup('CREATE', $this->highPriorityStream, $this->consumerGroup, '0', true);
        } catch (\Exception $e) {
            // Group уже существует
        }
    }

    public function getPendingCount(): int
    {
        $pending = Redis::xpending(
            $this->streamKey,
            $this->consumerGroup,
            '-',
            '+',
            100000
        );

        return count($pending);
    }
}
