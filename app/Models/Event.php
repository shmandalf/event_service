<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

class Event extends Model
{
    use HasFactory, HasUuids;

    const PRIORITY_HIGH = 8;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'user_id',
        'event_type',
        'timestamp',
        'priority',
        'payload',
        'metadata',
        'status',
        'idempotency_key',
        'retry_count',
        'last_error',
    ];

    protected $casts = [
        'id' => 'string',
        'user_id' => 'string',
        'timestamp' => 'datetime',
        'payload' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function newUniqueId(): string
    {
        return (string) Uuid::uuid7(); // Generates ordered UUIDs (v7)
    }

    public function isHighPriority(): bool
    {
        return $this->priority >= self::PRIORITY_HIGH;
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function markAsProcessed(): void
    {
        $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'last_error' => $error,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    public function shouldRetry(): bool
    {
        return $this->status === 'failed'
            && $this->retry_count < config('queue.worker.max_retries', 3);
    }
}
