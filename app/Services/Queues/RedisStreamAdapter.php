<?php

namespace App\Services\Queues;

use App\DTO\EventData;
use App\Services\MetricsService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class RedisStreamAdapter implements QueueAdapterInterface
{
    private const CONSUMER_GROUP = 'event_processors';
    private const MAX_LEN = 10000;
    private const CLAIM_TIMEOUT = 5000; // unused

    private string $streamKey;
    private string $highPriorityStream;
    private string $consumerGroup;
    private int $maxLen;
    private string $consumerId;

    public function __construct(
        private MetricsService $metrics,
        private array $config
    ) {
        $this->streamKey = $config['stream_key'] ?? 'events_stream';
        $this->highPriorityStream = $config['high_priority_stream'] ?? 'events_high_priority';
        $this->consumerGroup = $config['consumer_group'] ?? self::CONSUMER_GROUP;
        $this->maxLen = $config['max_len'] ?? self::MAX_LEN;
        $this->consumerId = 'redis_consumer_' . gethostname() . '_' . getmypid();

        $this->ensureConsumerGroup();
    }

    public function push(EventData $event, int $priority = 0): string
    {
        $startTime = microtime(true);

        try {
            $streamKey = $priority >= 8 ? $this->highPriorityStream : $this->streamKey;

            $messageId = Redis::xadd(
                $streamKey,
                '*',
                [
                    'event' => json_encode([
                        'id' => $event->id,
                        'user_id' => $event->userId,
                        'event_type' => $event->eventType,
                        'timestamp' => $event->timestamp->toISOString(),
                        'payload' => $event->payload,
                        'metadata' => $event->metadata,
                        'priority' => $priority,
                        'idempotency_key' => $event->idempotencyKey,
                        '_source' => 'redis',
                        '_priority' => $priority,
                    ]),
                    'timestamp' => now()->getTimestamp(),
                    'attempts' => 0,
                ],
                $this->maxLen,
                true
            );

            $this->metrics->increment('redis_stream_messages_published_total', [
                'stream' => $streamKey,
                'priority' => (string) $priority,
            ]);

            $duration = microtime(true) - $startTime;
            $this->metrics->histogram('redis_stream_push_duration_seconds', $duration, [
                'stream' => $streamKey,
            ]);

            Log::debug('Message published to Redis Stream', [
                'event_id' => $event->id,
                'stream' => $streamKey,
                'message_id' => $messageId,
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return $messageId;
        } catch (\Exception $e) {
            $this->metrics->increment('redis_stream_push_errors_total');

            Log::error('Failed to publish to Redis Stream', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Redis Stream publish failed: ' . $e->getMessage());
        }
    }

    public function consume(string $stream, callable $handler, int $timeout = 0): void
    {
        $startTime = microtime(true);

        try {
            // Читаем сообщения из стрима
            $messages = Redis::xreadgroup(
                $this->consumerGroup,
                $this->consumerId,
                [$stream => '>'],
                10, // limit
                $timeout > 0 ? $timeout * 1000 : 1000 // milliseconds
            );

            if (empty($messages)) {
                return;
            }

            $processed = 0;

            foreach ($messages as $streamName => $streamMessages) {
                foreach ($streamMessages as $messageId => $message) {
                    try {
                        // Декодируем событие
                        $eventData = json_decode($message['event'], true, 512, JSON_THROW_ON_ERROR);

                        // Создаем DTO
                        $event = EventData::fromArray(array_merge($eventData, [
                            '_source' => 'redis',
                            '_stream' => $streamName,
                            '_message_id' => $messageId,
                        ]));

                        // Вызываем обработчик
                        $handler($event);

                        // Подтверждаем обработку
                        Redis::xack($streamName, $this->consumerGroup, [$messageId]);
                        $processed++;
                    } catch (\JsonException $e) {
                        // Невалидный JSON - ACK и логируем
                        Redis::xack($streamName, $this->consumerGroup, [$messageId]);

                        $this->metrics->increment('redis_stream_message_errors_total', [
                            'error_type' => 'json_decode',
                            'stream' => $streamName,
                        ]);

                        Log::error('Invalid JSON in Redis Stream message', [
                            'stream' => $streamName,
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ]);
                    } catch (\Exception $e) {
                        // Бизнес-ошибка - увеличиваем счетчик попыток и возвращаем
                        $attempts = (int) ($message['attempts'] ?? 0) + 1;

                        if ($attempts >= 3) {
                            // Максимальное количество попыток - отправляем в DLQ
                            Redis::xack($streamName, $this->consumerGroup, [$messageId]);
                            $this->sendToRedisDLQ($messageId, $message, $streamName, $e);
                        } else {
                            // Обновляем счетчик попыток и возвращаем
                            $message['attempts'] = $attempts;
                            $message['last_error'] = $e->getMessage();
                            Redis::xadd($streamName, '*', $message, $this->maxLen, true);
                            Redis::xack($streamName, $this->consumerGroup, [$messageId]);
                        }

                        $this->metrics->increment('redis_stream_message_errors_total', [
                            'error_type' => 'processing',
                            'stream' => $streamName,
                        ]);

                        Log::error('Failed to process Redis Stream message', [
                            'stream' => $streamName,
                            'message_id' => $messageId,
                            'attempts' => $attempts,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $duration = microtime(true) - $startTime;

            if ($processed > 0) {
                $this->metrics->increment('redis_stream_messages_processed_total', [
                    'stream' => $stream,
                    'count' => (string) $processed,
                ]);

                $this->metrics->histogram('redis_stream_consume_duration_seconds', $duration, [
                    'stream' => $stream,
                    'processed' => (string) $processed,
                ]);
            }
        } catch (\Exception $e) {
            $this->metrics->increment('redis_stream_consume_errors_total');

            Log::error('Redis Stream consume error', [
                'stream' => $stream,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Забрать зависшие сообщения
     */
    /*
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

            $pending = Redis::xpending(
                $stream,
                self::CONSUMER_GROUP,
                '-', // start id
                '+', // end id
                $limit,
                ['IDLE' => 30000] // 30 секунд
            );

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
    */

    /**
     * Отправка в Redis Dead Letter Stream
     */
    private function sendToRedisDLQ(string $messageId, array $message, string $stream, \Exception $e): void
    {
        try {
            Redis::xadd('events_dlq_stream', '*', [
                'original_message_id' => $messageId,
                'original_stream' => $stream,
                'event' => $message['event'] ?? '',
                'error' => $e->getMessage(),
                'failed_at' => now()->toISOString(),
                'attempts' => $message['attempts'] ?? 0,
            ], 10000, true);

            $this->metrics->increment('redis_dlq_messages_total');
        } catch (\Exception $dlqError) {
            Log::error('Failed to send to Redis DLQ', [
                'original_error' => $e->getMessage(),
                'dlq_error' => $dlqError->getMessage(),
            ]);
        }
    }

    /**
     * Получить статистику очереди
     */
    public function getQueueStats(string $stream): array
    {
        try {
            $info = Redis::xinfo('STREAM', $stream);

            return [
                'length' => $info['length'] ?? 0,
                'last_generated_id' => $info['last-generated-id'] ?? '0-0',
                'max_deleted_entry_id' => $info['max-deleted-entry-id'] ?? '0-0',
                'entries_added' => $info['entries-added'] ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'length' => 0,
                'last_generated_id' => '0-0',
                'max_deleted_entry_id' => '0-0',
                'entries_added' => 0,
            ];
        }
    }

    /**
     * Получить pending сообщения
     */
    public function getPendingStats(string $stream): array
    {
        try {
            $pending = Redis::xpending($stream, $this->consumerGroup, '-', '+', 100);

            return [
                'pending_count' => count($pending),
                'pending_messages' => $pending,
            ];
        } catch (\Exception $e) {
            return ['pending_count' => 0, 'pending_messages' => []];
        }
    }

    /**
     * Забрать зависшие сообщения
     */
    public function claimPendingMessages(string $stream, int $limit, int $idleTime = 30000): int
    {
        $claimed = 0;

        try {
            // Получаем pending сообщения старше idleTime
            $pending = Redis::xpending(
                $stream,
                $this->consumerGroup,
                '-',
                '+',
                $limit,
                ['IDLE' => $idleTime]
            );

            if (empty($pending)) {
                return 0;
            }

            $messageIds = array_column($pending, 0);

            // Забираем себе
            $claimedMessages = Redis::xclaim(
                $stream,
                $this->consumerGroup,
                $this->consumerId,
                $idleTime * 2,
                $messageIds,
                [
                    'JUSTID' => true,
                    'FORCE' => true,
                ]
            );

            $claimed = count($claimedMessages);

            if ($claimed > 0) {
                $this->metrics->increment('redis_stream_messages_claimed_total', [
                    'stream' => $stream,
                    'count' => (string) $claimed,
                ]);

                Log::info('Claimed pending messages', [
                    'stream' => $stream,
                    'claimed' => $claimed,
                    'consumer' => $this->consumerId,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to claim pending messages', [
                'stream' => $stream,
                'error' => $e->getMessage(),
            ]);
        }

        return $claimed;
    }

    /**
     * Создание consumer group если не существует
     */
    private function ensureConsumerGroup(): void
    {
        foreach ([$this->streamKey, $this->highPriorityStream] as $stream) {
            try {
                Redis::xgroup('CREATE', $stream, $this->consumerGroup, '0', true);
            } catch (\Exception $e) {
                // Group уже существует - это нормально
            }
        }
    }

    public function disconnect(): void
    {
        // Для Redis ничего специально делать не нужно
    }
}
