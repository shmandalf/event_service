<?php

namespace App\Services\Queues;

use App\DTO\EventData;
use App\Services\MetricsService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RabbitMQAdapter implements QueueAdapterInterface
{
    private const EXCHANGE = 'events';
    private const DLX_EXCHANGE = 'events.dlx';
    private const HIGH_PRIORITY_QUEUE = 'events.high_priority';
    private const NORMAL_QUEUE = 'events.normal';
    private const DLQ_QUEUE = 'events.dead_letter';

    private AMQPStreamConnection $connection;
    private \PhpAmqpLib\Channel\AMQPChannel $channel;

    public function __construct(
        private MetricsService $metrics,
        private array $config
    ) {
        $this->connect();
        $this->declareInfrastructure();
    }

    private function connect(): void
    {
        $startTime = microtime(true);

        try {
            $this->connection = new AMQPStreamConnection(
                host: $this->config['host'],
                port: $this->config['port'],
                user: $this->config['user'],
                password: $this->config['password'],
                vhost: $this->config['vhost'],
                insist: false,
                login_method: 'AMQPLAIN',
                login_response: null,
                locale: 'en_US',
                connection_timeout: 3.0,
                read_write_timeout: 3.0,
                keepalive: true,
                heartbeat: 60
            );

            $this->channel = $this->connection->channel();

            // QoS - quality of service
            $this->channel->basic_qos(
                prefetch_size: 0,
                prefetch_count: $this->config['qos']['prefetch_count'] ?? 10,
                a_global: false
            );

            $duration = microtime(true) - $startTime;
            $this->metrics->histogram('rabbitmq_connection_duration_seconds', $duration, [
                'operation' => 'connect'
            ]);

            Log::info('RabbitMQ connected', [
                'host' => $this->config['host'],
                'duration_ms' => round($duration * 1000, 2),
            ]);
        } catch (Throwable $e) {
            $this->metrics->increment('rabbitmq_connection_errors_total');
            Log::error('Failed to connect to RabbitMQ', [
                'error' => $e->getMessage(),
                'config' => [
                    'host' => $this->config['host'],
                    'port' => $this->config['port'],
                    'user' => $this->config['user'],
                ],
            ]);

            throw new \RuntimeException('RabbitMQ connection failed: ' . $e->getMessage());
        }
    }

    private function declareInfrastructure(): void
    {
        try {
            // Объявляем основную exchange
            $this->channel->exchange_declare(
                exchange: self::EXCHANGE,
                type: 'direct',
                passive: false,
                durable: true,
                auto_delete: false,
                internal: false,
                nowait: false,
                arguments: new AMQPTable()
            );

            // Объявляем DLX exchange
            $this->channel->exchange_declare(
                exchange: self::DLX_EXCHANGE,
                type: 'direct',
                passive: false,
                durable: true,
                auto_delete: false
            );

            // Объявляем high priority очередь
            $this->channel->queue_declare(
                queue: self::HIGH_PRIORITY_QUEUE,
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: false,
                nowait: false,
                arguments: new AMQPTable([
                    'x-max-priority' => 10,
                    'x-dead-letter-exchange' => self::DLX_EXCHANGE,
                    'x-dead-letter-routing-key' => 'events.dead',
                    'x-message-ttl' => 86400000, // 24 часа
                    'x-queue-mode' => 'lazy', // Сообщения на диск
                ])
            );

            // Объявляем normal очередь
            $this->channel->queue_declare(
                queue: self::NORMAL_QUEUE,
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: false,
                nowait: false,
                arguments: new AMQPTable([
                    'x-dead-letter-exchange' => self::DLX_EXCHANGE,
                    'x-dead-letter-routing-key' => 'events.dead',
                    'x-message-ttl' => 604800000, // 7 дней
                ])
            );

            // Объявляем dead letter очередь
            $this->channel->queue_declare(
                queue: self::DLQ_QUEUE,
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: false,
                nowait: false,
                arguments: new AMQPTable([
                    'x-queue-mode' => 'lazy',
                ])
            );

            // Биндинги
            $this->channel->queue_bind(self::HIGH_PRIORITY_QUEUE, self::EXCHANGE, 'high');
            $this->channel->queue_bind(self::NORMAL_QUEUE, self::EXCHANGE, 'normal');
            $this->channel->queue_bind(self::DLQ_QUEUE, self::DLX_EXCHANGE, 'events.dead');

            Log::debug('RabbitMQ infrastructure declared');
        } catch (Throwable $e) {
            Log::error('Failed to declare RabbitMQ infrastructure', [
                'error' => $e->getMessage(),
            ]);

            // Не падаем, т.к. инфраструктура может быть уже объявлена
        }
    }

    public function push(EventData $event, int $priority = 0): string
    {
        $startTime = microtime(true);

        try {
            // Определяем очередь по приоритету
            $routingKey = $priority >= 8 ? 'high' : 'normal';
            $queue = $routingKey === 'high' ? self::HIGH_PRIORITY_QUEUE : self::NORMAL_QUEUE;

            // Подготавливаем сообщение
            $messageBody = json_encode([
                'id' => $event->id,
                'user_id' => $event->userId,
                'event_type' => $event->eventType,
                'timestamp' => $event->timestamp->toISOString(),
                'payload' => $event->payload,
                'metadata' => $event->metadata,
                'priority' => $priority,
                'idempotency_key' => $event->idempotencyKey,
                'published_at' => now()->toISOString(),
            ], JSON_THROW_ON_ERROR);

            $message = new AMQPMessage(
                body: $messageBody,
                properties: [
                    'content_type' => 'application/json',
                    'content_encoding' => 'utf-8',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'priority' => $priority,
                    'timestamp' => time(),
                    'message_id' => $event->id,
                    'app_id' => 'event-service',
                    'headers' => new AMQPTable([
                        'x-event-type' => $event->eventType,
                        'x-priority' => $priority,
                        'x-user-id' => $event->userId,
                    ]),
                ]
            );

            // Публикуем сообщение
            $this->channel->basic_publish(
                msg: $message,
                exchange: self::EXCHANGE,
                routing_key: $routingKey,
                mandatory: false,
                immediate: false,
                ticket: null
            );

            $messageId = $event->id;

            // Метрики
            $duration = microtime(true) - $startTime;
            $this->metrics->histogram('rabbitmq_push_duration_seconds', $duration, [
                'priority' => (string) $priority,
                'queue' => $queue,
            ]);

            $this->metrics->increment('rabbitmq_messages_published_total', [
                'queue' => $queue,
                'priority' => (string) $priority,
            ]);

            Log::debug('Message published to RabbitMQ', [
                'event_id' => $event->id,
                'queue' => $queue,
                'priority' => $priority,
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return $messageId;
        } catch (Throwable $e) {
            $this->metrics->increment('rabbitmq_push_errors_total');

            Log::error('Failed to publish message to RabbitMQ', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException('Failed to publish to RabbitMQ: ' . $e->getMessage());
        }
    }

    /**
     * Потреблять сообщения из очереди с обработчиком событий
     */
    public function consume(string $queue, callable $handler, int $timeout = 0): void
    {
        $consumerTag = 'event_consumer_' . gethostname() . '_' . getmypid();

        try {
            $callback = function (AMQPMessage $message) use ($handler, $queue, $consumerTag) {
                $startTime = microtime(true);

                try {
                    $body = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

                    // Создаем EventData из сообщения
                    $eventData = EventData::fromArray([
                        'id' => $body['id'] ?? $this->generateEventId($message),
                        'user_id' => $body['user_id'],
                        'event_type' => $body['event_type'],
                        'timestamp' => $body['timestamp'],
                        'payload' => $body['payload'],
                        'metadata' => $body['metadata'] ?? null,
                        'priority' => $body['priority'] ?? 0,
                        'idempotency_key' => $body['idempotency_key'] ?? null,
                        '_source' => 'rabbitmq',
                        '_queue' => $queue,
                        '_message_id' => $message->getDeliveryTag(),
                    ]);

                    // Вызываем переданный обработчик
                    $handler($eventData);

                    // Подтверждаем обработку
                    $message->ack();

                    // Метрики
                    $duration = microtime(true) - $startTime;
                    $this->metrics->histogram('rabbitmq_message_processing_duration_seconds', $duration, [
                        'queue' => $queue,
                        'consumer' => $consumerTag,
                    ]);

                    $this->metrics->increment('rabbitmq_messages_processed_total', [
                        'queue' => $queue,
                        'status' => 'success',
                        'consumer' => $consumerTag,
                    ]);

                    Log::debug('RabbitMQ message processed', [
                        'event_id' => $eventData->id,
                        'queue' => $queue,
                        'consumer' => $consumerTag,
                        'processing_time_ms' => round($duration * 1000, 2),
                        'delivery_tag' => $message->getDeliveryTag(),
                    ]);
                } catch (\JsonException $e) {
                    // Невалидный JSON - отправляем в DLQ без ретраев
                    $message->reject(false); // false = не requeue

                    $this->metrics->increment('rabbitmq_message_processing_errors_total', [
                        'queue' => $queue,
                        'error_type' => 'json_decode',
                        'consumer' => $consumerTag,
                    ]);

                    Log::error('Invalid JSON in RabbitMQ message', [
                        'queue' => $queue,
                        'error' => $e->getMessage(),
                        'body_preview' => substr($message->getBody(), 0, 200),
                        'consumer' => $consumerTag,
                    ]);
                } catch (Throwable $e) {
                    // Бизнес-ошибка - ретраем сообщение
                    $message->nack(true); // true = requeue

                    $this->metrics->increment('rabbitmq_message_processing_errors_total', [
                        'queue' => $queue,
                        'error_type' => get_class($e),
                        'consumer' => $consumerTag,
                    ]);

                    Log::error('Failed to process RabbitMQ message', [
                        'queue' => $queue,
                        'error' => $e->getMessage(),
                        'consumer' => $consumerTag,
                        'delivery_tag' => $message->getDeliveryTag(),
                    ]);
                }
            };

            // Настраиваем QoS перед началом потребления
            $this->channel->basic_qos(
                prefetch_size: 0,
                prefetch_count: $this->config['qos']['prefetch_count'] ?? 10,
                a_global: false
            );

            // Начинаем потребление
            $this->channel->basic_consume(
                queue: $queue,
                consumer_tag: $consumerTag,
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: $callback,
                ticket: null,
                arguments: new AMQPTable()
            );

            Log::info('Started RabbitMQ consumer', [
                'queue' => $queue,
                'consumer_tag' => $consumerTag,
                'prefetch_count' => $this->config['qos']['prefetch_count'] ?? 10,
            ]);

            // Ожидаем сообщения
            while ($this->channel->is_consuming()) {
                try {
                    $this->channel->wait(timeout: $timeout);
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    // Таймаут - нормально для non-blocking режима
                    break;
                } catch (Throwable $e) {
                    $this->metrics->increment('rabbitmq_consume_wait_errors_total');
                    Log::error('Error during channel wait', [
                        'queue' => $queue,
                        'error' => $e->getMessage(),
                        'consumer' => $consumerTag,
                    ]);
                    break;
                }
            }
        } catch (Throwable $e) {
            $this->metrics->increment('rabbitmq_consume_errors_total');

            Log::error('RabbitMQ consume error', [
                'queue' => $queue,
                'consumer_tag' => $consumerTag,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            // Всегда отменяем consumer после завершения ожидания
            $this->cancelConsumer($consumerTag);
        }
    }

    private function cancelConsumer(string $consumerTag)
    {
        try {
            if ($this->channel && $this->channel->is_open()) {
                $this->channel->basic_cancel($consumerTag, false, true);
                Log::debug('Cancelled RabbitMQ consumer', ['consumer_tag' => $consumerTag]);
            }
        } catch (\Exception $e) {
            Log::warning('Error cancelling consumer', [
                'consumer_tag' => $consumerTag,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Генерация ID события если его нет в сообщении
     */
    private function generateEventId(AMQPMessage $message): string
    {
        return 'rabbitmq_' . $message->getDeliveryTag() . '_' . uniqid();
    }

    public function getQueueStats(string $queue): array
    {
        try {
            $result = $this->channel->queue_declare(
                queue: $queue,
                passive: true // Только получить информацию
            );

            return [
                'message_count' => $result[1] ?? 0,
                'consumer_count' => $result[2] ?? 0,
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to get RabbitMQ queue stats', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            return ['message_count' => 0, 'consumer_count' => 0];
        }
    }

    public function disconnect(): void
    {
        try {
            if (isset($this->channel) && $this->channel->is_open()) {
                $this->channel->close();
            }

            if (isset($this->connection) && $this->connection->isConnected()) {
                $this->connection->close();
            }

            Log::info('RabbitMQ disconnected');
        } catch (Throwable $e) {
            Log::warning('Error during RabbitMQ disconnect', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
