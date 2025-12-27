<?php

namespace App\DTO;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

class EventData implements Arrayable
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $eventType,
        public readonly Carbon $timestamp,
        public readonly array $payload,
        public readonly ?array $metadata = null,
        public readonly int $priority = 0,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $source = null, // Новое поле: 'rabbitmq', 'redis', 'api'
        public readonly ?array $queueInfo = null, // Информация об очереди
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? (string) Str::orderedUuid(),
            userId: (string) $data['user_id'],
            eventType: $data['event_type'],
            timestamp: Carbon::parse($data['timestamp']),
            payload: $data['payload'],
            metadata: $data['metadata'] ?? null,
            priority: $data['priority'] ?? self::calculatePriority($data['event_type']),
            idempotencyKey: $data['idempotency_key'] ?? null,
            ip: $data['_ip'] ?? null,
            userAgent: $data['_user_agent'] ?? null,
            source: $data['_source'] ?? 'api',
            queueInfo: [
                'queue' => $data['_queue'] ?? null,
                'message_id' => $data['_message_id'] ?? null,
            ],
        );
    }

    private static function calculatePriority(string $eventType): int
    {
        return match ($eventType) {
            'purchase', 'subscription', 'payment' => 9,
            'login', 'logout', 'signup' => 5,
            default => 1,
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'event_type' => $this->eventType,
            'timestamp' => $this->timestamp->toISOString(),
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'priority' => $this->priority,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    public function isHighPriority(): bool
    {
        return $this->priority >= 8;
    }
}
