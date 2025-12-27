<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class DeadLetterQueueManager
{
    private const DLQ_QUEUE = 'events.dlq';
    private const DLX_EXCHANGE = 'events.dlx';
    private const RETRY_QUEUE = 'events.retry';
    private const RETRY_EXCHANGE = 'events.retry';

    private ?AMQPStreamConnection $connection = null;
    private ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;

    public function __construct(
        private MetricsService $metrics,
        private RetryManager $retryManager,
        private array $config
    ) {}

    /**
     * Отправить сообщение в DLQ
     */
    public function sendToDLQ(
        string $originalQueue,
        string $messageBody,
        array $headers,
        string $error,
        int $retryCount = 0
    ): void {
        $startTime = microtime(true);

        try {
            $this->ensureConnection();

            $dlqMessage = [
                'original_queue' => $originalQueue,
                'original_message' => $messageBody,
                'error' => $error,
                'retry_count' => $retryCount,
                'failed_at' => now()->toISOString(),
                'headers' => $headers,
            ];

            $message = new AMQPMessage(
                body: json_encode($dlqMessage, JSON_THROW_ON_ERROR),
                properties: [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'timestamp' => time(),
                    'headers' => new \PhpAmqpLib\Wire\AMQPTable([
                        'x-original-queue' => $originalQueue,
                        'x-error' => $error,
                        'x-retry-count' => $retryCount,
                    ]),
                ]
            );

            $this->channel->basic_publish(
                $message,
                self::DLX_EXCHANGE,
                'dead'
            );

            $this->metrics->increment('dlq_messages_total', [
                'original_queue' => $originalQueue,
                'retry_count' => (string) $retryCount,
            ]);

            $duration = microtime(true) - $startTime;
            $this->metrics->histogram('dlq_send_duration_seconds', $duration);

            Log::warning('Message sent to DLQ', [
                'original_queue' => $originalQueue,
                'error' => $error,
                'retry_count' => $retryCount,
                'duration_ms' => round($duration * 1000, 2),
            ]);
        } catch (\Throwable $e) {
            $this->metrics->increment('dlq_send_errors_total');

            Log::error('Failed to send message to DLQ', [
                'original_queue' => $originalQueue,
                'error' => $error,
                'dlq_error' => $e->getMessage(),
            ]);

            // Fallback: сохраняем в Redis если RabbitMQ недоступен
            $this->sendToRedisDLQ($originalQueue, $messageBody, $error, $retryCount);
        }
    }

    /**
     * Отправить сообщение в retry очередь
     */
    public function sendToRetryQueue(
        string $originalQueue,
        string $messageBody,
        array $headers,
        string $error,
        int $retryCount
    ): void {
        $startTime = microtime(true);

        try {
            $this->ensureConnection();

            $retryMessage = [
                'original_queue' => $originalQueue,
                'original_message' => $messageBody,
                'error' => $error,
                'retry_count' => $retryCount,
                'scheduled_for' => now()->addMilliseconds(
                    $this->retryManager->calculateDelay($retryCount)
                )->toISOString(),
                'headers' => $headers,
            ];

            $message = new AMQPMessage(
                body: json_encode($retryMessage, JSON_THROW_ON_ERROR),
                properties: [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'timestamp' => time(),
                    'expiration' => (string) $this->retryManager->calculateDelay($retryCount),
                    'headers' => new \PhpAmqpLib\Wire\AMQPTable([
                        'x-original-queue' => $originalQueue,
                        'x-retry-count' => $retryCount,
                        'x-error' => $error,
                    ]),
                ]
            );

            $this->channel->basic_publish(
                $message,
                self::RETRY_EXCHANGE,
                'retry'
            );

            $this->metrics->increment('retry_queue_messages_total', [
                'original_queue' => $originalQueue,
                'retry_count' => (string) $retryCount,
            ]);

            $duration = microtime(true) - $startTime;
            $this->metrics->histogram('retry_queue_send_duration_seconds', $duration);

            Log::info('Message sent to retry queue', [
                'original_queue' => $originalQueue,
                'retry_count' => $retryCount,
                'delay_ms' => $this->retryManager->calculateDelay($retryCount),
                'duration_ms' => round($duration * 1000, 2),
            ]);
        } catch (\Throwable $e) {
            $this->metrics->increment('retry_queue_send_errors_total');

            Log::error('Failed to send message to retry queue', [
                'original_queue' => $originalQueue,
                'error' => $error,
                'retry_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fallback: отправить в Redis DLQ если RabbitMQ недоступен
     */
    private function sendToRedisDLQ(
        string $originalQueue,
        string $messageBody,
        string $error,
        int $retryCount
    ): void {
        try {
            $dlqData = [
                'original_queue' => $originalQueue,
                'message' => $messageBody,
                'error' => $error,
                'retry_count' => $retryCount,
                'failed_at' => now()->toISOString(),
                'storage' => 'redis',
            ];

            \Illuminate\Support\Facades\Redis::rpush(
                'events:dlq:backup',
                json_encode($dlqData, JSON_THROW_ON_ERROR)
            );

            // Ограничиваем размер backup очереди
            \Illuminate\Support\Facades\Redis::ltrim('events:dlq:backup', 0, 9999);

            $this->metrics->increment('redis_dlq_backup_messages_total');

            Log::warning('Message saved to Redis DLQ backup', [
                'original_queue' => $originalQueue,
                'retry_count' => $retryCount,
            ]);
        } catch (\Throwable $e) {
            Log::critical('Failed to save to Redis DLQ backup', [
                'original_queue' => $originalQueue,
                'error' => $error,
                'backup_error' => $e->getMessage(),
            ]);

            // Последний fallback: логируем в файл
            $this->logToFileDLQ($originalQueue, $messageBody, $error, $retryCount);
        }
    }

    /**
     * Последний fallback: логирование в файл
     */
    private function logToFileDLQ(
        string $originalQueue,
        string $messageBody,
        string $error,
        int $retryCount
    ): void {
        $logMessage = sprintf(
            "[%s] DLQ Backup Failed: queue=%s, retry=%d, error=%s, message=%s\n",
            now()->toISOString(),
            $originalQueue,
            $retryCount,
            $error,
            substr($messageBody, 0, 500)
        );

        @file_put_contents(
            storage_path('logs/dlq_backup.log'),
            $logMessage,
            FILE_APPEND
        );

        $this->metrics->increment('file_dlq_backup_messages_total');
    }

    /**
     * Восстановить сообщения из Redis backup в RabbitMQ DLQ
     */
    public function restoreFromBackup(): int
    {
        $restored = 0;

        try {
            $this->ensureConnection();

            // Берем до 100 сообщений за раз
            for ($i = 0; $i < 100; $i++) {
                $messageJson = \Illuminate\Support\Facades\Redis::lpop('events:dlq:backup');

                if (!$messageJson) {
                    break;
                }

                $messageData = json_decode($messageJson, true, 512, JSON_THROW_ON_ERROR);

                // Восстанавливаем в RabbitMQ DLQ
                $this->sendToDLQ(
                    $messageData['original_queue'],
                    $messageData['message'],
                    $messageData['headers'] ?? [],
                    $messageData['error'],
                    $messageData['retry_count']
                );

                $restored++;
            }

            if ($restored > 0) {
                Log::info('Restored messages from Redis backup', [
                    'count' => $restored,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to restore from Redis backup', [
                'error' => $e->getMessage(),
                'restored' => $restored,
            ]);
        }

        return $restored;
    }

    /**
     * Получить статистику DLQ
     */
    public function getStats(): array
    {
        try {
            $this->ensureConnection();

            $dlqInfo = $this->channel->queue_declare(self::DLQ_QUEUE, true);
            $retryInfo = $this->channel->queue_declare(self::RETRY_QUEUE, true);

            return [
                'dlq' => [
                    'message_count' => $dlqInfo[1] ?? 0,
                    'consumer_count' => $dlqInfo[2] ?? 0,
                ],
                'retry_queue' => [
                    'message_count' => $retryInfo[1] ?? 0,
                    'consumer_count' => $retryInfo[2] ?? 0,
                ],
                'redis_backup' => \Illuminate\Support\Facades\Redis::llen('events:dlq:backup'),
            ];
        } catch (\Throwable $e) {
            return [
                'dlq' => ['message_count' => 0, 'consumer_count' => 0],
                'retry_queue' => ['message_count' => 0, 'consumer_count' => 0],
                'redis_backup' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Установить соединение с RabbitMQ
     */
    private function ensureConnection(): void
    {
        if (!$this->connection || !$this->connection->isConnected()) {
            $this->connection = new AMQPStreamConnection(
                host: $this->config['host'],
                port: $this->config['port'],
                user: $this->config['user'],
                password: $this->config['password'],
                vhost: $this->config['vhost']
            );

            $this->channel = $this->connection->channel();

            // Объявляем DLQ и retry очереди
            $this->channel->queue_declare(self::DLQ_QUEUE, false, true, false, false);
            $this->channel->queue_declare(self::RETRY_QUEUE, false, true, false, false);
        }
    }

    public function __destruct()
    {
        try {
            if ($this->channel && $this->channel->is_open()) {
                $this->channel->close();
            }

            if ($this->connection && $this->connection->isConnected()) {
                $this->connection->close();
            }
        } catch (\Throwable $e) {
            // Игнорируем ошибки при деструкторе
        }
    }
}
