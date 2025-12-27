<?php

namespace App\Services\CircuitBreaker;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class QueueCircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private string $queueType; // 'rabbitmq' или 'redis'
    private array $config;

    public function __construct(string $queueType)
    {
        $this->queueType = $queueType;
        $this->config = config("circuit_breaker.queues.{$queueType}", [
            'failure_threshold' => 10,
            'success_threshold' => 5,
            'timeout' => 60,
            'half_open_timeout' => 30,
            'monitoring_window' => 300, // 5 минут
        ]);
    }

    /**
     * Проверить доступна ли очередь
     */
    public function isAvailable(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_OPEN) {
            // Проверяем не истек ли таймаут
            if ($this->isTimeoutExpired()) {
                $this->setState(self::STATE_HALF_OPEN);
                return true; // Пробуем один запрос
            }
            return false;
        }

        return true;
    }

    /**
     * Записать успешную операцию
     */
    public function recordSuccess(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            $successCount = Cache::increment("circuit:queue:{$this->queueType}:success_count");

            if ($successCount >= $this->config['success_threshold']) {
                $this->setState(self::STATE_CLOSED);
                $this->resetCounters();

                Log::info('Queue circuit breaker closed', [
                    'queue' => $this->queueType,
                    'success_count' => $successCount,
                ]);
            }
        } else {
            $this->resetFailureCounter();
        }
    }

    /**
     * Записать неудачную операцию
     */
    public function recordFailure(string $errorType = 'unknown'): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            // При неудаче в half-open сразу открываем
            $this->setState(self::STATE_OPEN);
            $this->recordOpenTime();

            Log::warning('Queue circuit breaker opened (half-open failure)', [
                'queue' => $this->queueType,
                'error_type' => $errorType,
            ]);
        } else {
            $failureCount = Cache::increment("circuit:queue:{$this->queueType}:failure_count");

            // Записываем ошибку для мониторинга
            $this->recordError($errorType);

            if ($failureCount >= $this->config['failure_threshold']) {
                $this->setState(self::STATE_OPEN);
                $this->recordOpenTime();

                Log::warning('Queue circuit breaker opened', [
                    'queue' => $this->queueType,
                    'failure_count' => $failureCount,
                    'error_type' => $errorType,
                ]);
            }
        }
    }

    /**
     * Записать ошибку для статистики
     */
    private function recordError(string $errorType): void
    {
        $key = "circuit:queue:{$this->queueType}:errors:" . now()->format('Y-m-d-H');
        Cache::increment($key);
        Cache::expire($key, 3600); // 1 час
    }

    /**
     * Получить статистику ошибок
     */
    public function getErrorStats(): array
    {
        $pattern = "circuit:queue:{$this->queueType}:errors:*";

        return [
            'state' => $this->getState(),
            'failure_count' => Cache::get("circuit:queue:{$this->queueType}:failure_count", 0),
            'success_count' => Cache::get("circuit:queue:{$this->queueType}:success_count", 0),
            'opened_at' => Cache::get("circuit:queue:{$this->queueType}:opened_at"),
            'is_available' => $this->isAvailable(),
            'config' => $this->config,
        ];
    }

    /**
     * Принудительно открыть circuit breaker
     */
    public function forceOpen(string $reason = 'manual'): void
    {
        $this->setState(self::STATE_OPEN);
        $this->recordOpenTime();

        Log::warning('Queue circuit breaker forced open', [
            'queue' => $this->queueType,
            'reason' => $reason,
        ]);
    }

    /**
     * Принудительно закрыть circuit breaker
     */
    public function forceClose(string $reason = 'manual'): void
    {
        $this->setState(self::STATE_CLOSED);
        $this->resetCounters();

        Log::info('Queue circuit breaker forced closed', [
            'queue' => $this->queueType,
            'reason' => $reason,
        ]);
    }

    /**
     * Получить состояние
     */
    public function getState(): string
    {
        return Cache::get("circuit:queue:{$this->queueType}:state", self::STATE_CLOSED);
    }

    private function setState(string $state): void
    {
        Cache::put("circuit:queue:{$this->queueType}:state", $state, 3600);
    }

    private function resetFailureCounter(): void
    {
        Cache::forget("circuit:queue:{$this->queueType}:failure_count");
    }

    private function resetCounters(): void
    {
        Cache::forget("circuit:queue:{$this->queueType}:failure_count");
        Cache::forget("circuit:queue:{$this->queueType}:success_count");
    }

    private function recordOpenTime(): void
    {
        Cache::put("circuit:queue:{$this->queueType}:opened_at", now(), $this->config['timeout']);
    }

    private function isTimeoutExpired(): bool
    {
        $openedAt = Cache::get("circuit:queue:{$this->queueType}:opened_at");

        if (!$openedAt) {
            return true;
        }

        return now()->diffInSeconds($openedAt) > $this->config['timeout'];
    }
}
