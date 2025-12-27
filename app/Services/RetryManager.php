<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RetryManager
{
    private const MAX_RETRIES = 5;
    private const INITIAL_DELAY = 1000; // 1 секунда в миллисекундах
    private const MAX_DELAY = 60000;    // 1 минута
    private const BACKOFF_FACTOR = 2;

    public function __construct(
        private MetricsService $metrics
    ) {}

    /**
     * Проверить, нужно ли ретраить событие
     */
    public function shouldRetry(string $eventId, string $errorType): bool
    {
        $retryCount = $this->getRetryCount($eventId);

        if ($retryCount >= self::MAX_RETRIES) {
            $this->metrics->increment('retry_max_exceeded_total', [
                'error_type' => $errorType,
                'event_id' => $eventId,
            ]);

            Log::warning('Max retries exceeded', [
                'event_id' => $eventId,
                'retry_count' => $retryCount,
                'error_type' => $errorType,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Получить количество попыток ретрая
     */
    public function getRetryCount(string $eventId): int
    {
        return Cache::get("retry:count:{$eventId}", 0);
    }

    /**
     * Увеличить счетчик ретраев
     */
    public function incrementRetryCount(string $eventId): int
    {
        $count = Cache::increment("retry:count:{$eventId}");
        Cache::put("retry:count:{$eventId}", $count, 86400); // 24 часа

        $this->metrics->increment('retry_count_total', [
            'event_id' => $eventId,
            'count' => (string) $count,
        ]);

        return $count;
    }

    /**
     * Рассчитать задержку для следующего ретрая
     */
    public function calculateDelay(int $retryCount): int
    {
        // Экспоненциальная backoff: delay = initial * (backoff^retryCount)
        $delay = self::INITIAL_DELAY * pow(self::BACKOFF_FACTOR, $retryCount);

        // Добавляем jitter (±20%) для предотвращения thundering herd - крутяк
        $jitter = $delay * 0.2;
        $delay = $delay + rand(-$jitter, $jitter);

        // Ограничиваем максимальной задержкой
        $delay = min($delay, self::MAX_DELAY);

        $this->metrics->histogram('retry_delay_milliseconds', $delay, [
            'retry_count' => (string) $retryCount,
        ]);

        return (int) $delay;
    }

    /**
     * Очистить счетчик ретраев
     */
    public function clearRetryCount(string $eventId): void
    {
        Cache::forget("retry:count:{$eventId}");

        $this->metrics->increment('retry_cleared_total', [
            'event_id' => $eventId,
        ]);
    }

    /**
     * Получить статистику ретраев
     */
    public function getStats(): array
    {
        // Получаем ключи ретраев (в production нужен Redis SCAN)
        $pattern = 'retry:count:*';

        return [
            'max_retries' => self::MAX_RETRIES,
            'initial_delay_ms' => self::INITIAL_DELAY,
            'max_delay_ms' => self::MAX_DELAY,
            'backoff_factor' => self::BACKOFF_FACTOR,
        ];
    }
}
