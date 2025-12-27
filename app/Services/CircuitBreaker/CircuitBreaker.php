<?php

namespace App\Services\CircuitBreaker;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private string $service;
    private array $config;

    public function __construct(string $service)
    {
        $this->service = $service;
        $this->config = config("circuit_breaker.{$service}", [
            'failure_threshold' => 5,
            'success_threshold' => 3,
            'timeout' => 60, // секунды
            'half_open_timeout' => 30,
        ]);
    }

    public function isAvailable(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_OPEN) {
            // Проверяем, не истек ли таймаут
            if ($this->isTimeoutExpired()) {
                $this->setState(self::STATE_HALF_OPEN);
                return true; // Пробуем один запрос
            }
            return false;
        }

        return true;
    }

    public function recordSuccess(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            $successCount = Cache::increment("circuit:{$this->service}:success_count");

            if ($successCount >= $this->config['success_threshold']) {
                $this->setState(self::STATE_CLOSED);
                $this->resetCounters();
            }
        } else {
            $this->resetFailureCounter();
        }
    }

    public function recordFailure(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            // При неудаче в half-open сразу открываем
            $this->setState(self::STATE_OPEN);
            $this->recordOpenTime();
        } else {
            $failureCount = Cache::increment("circuit:{$this->service}:failure_count");

            if ($failureCount >= $this->config['failure_threshold']) {
                $this->setState(self::STATE_OPEN);
                $this->recordOpenTime();

                Log::warning('Circuit breaker opened', [
                    'service' => $this->service,
                    'failure_count' => $failureCount,
                ]);
            }
        }
    }

    public function getState(): string
    {
        return Cache::get("circuit:{$this->service}:state", self::STATE_CLOSED);
    }

    private function setState(string $state): void
    {
        Cache::put("circuit:{$this->service}:state", $state, 3600);
    }

    private function resetFailureCounter(): void
    {
        Cache::forget("circuit:{$this->service}:failure_count");
    }

    private function resetCounters(): void
    {
        Cache::forget("circuit:{$this->service}:failure_count");
        Cache::forget("circuit:{$this->service}:success_count");
    }

    private function recordOpenTime(): void
    {
        Cache::put("circuit:{$this->service}:opened_at", now(), $this->config['timeout']);
    }

    private function isTimeoutExpired(): bool
    {
        $openedAt = Cache::get("circuit:{$this->service}:opened_at");

        if (!$openedAt) {
            return true;
        }

        return now()->diffInSeconds($openedAt) > $this->config['timeout'];
    }

    public function getStats(): array
    {
        return [
            'service' => $this->service,
            'state' => $this->getState(),
            'failure_count' => Cache::get("circuit:{$this->service}:failure_count", 0),
            'success_count' => Cache::get("circuit:{$this->service}:success_count", 0),
            'opened_at' => Cache::get("circuit:{$this->service}:opened_at"),
            'is_available' => $this->isAvailable(),
        ];
    }
}
