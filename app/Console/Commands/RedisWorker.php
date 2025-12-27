<?php

namespace App\Console\Commands;

use App\Services\Queues\RedisStreamAdapter;
use App\Services\EventStreamProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RedisWorker extends Command
{
    protected $signature = 'redis:worker
                            {--batch-size=100 : Batch size for processing}
                            {--sleep=1 : Sleep seconds when no events}
                            {--timeout=30 : Command timeout}
                            {--memory=256 : Memory limit in MB}
                            {--stream=events_stream : Redis stream key}
                            {--claim-timeout=30 : Claim pending messages timeout}';

    protected $description = 'Process events from Redis Stream';

    private bool $shouldStop = false;

    public function handle(RedisStreamAdapter $redisAdapter, EventStreamProcessor $processor): int
    {
        $this->info('Starting Redis Stream Worker...');

        // Установка лимитов
        ini_set('memory_limit', $this->option('memory') . 'M');
        set_time_limit(0);

        $batchSize = (int) $this->option('batch-size');
        $sleepTime = (int) $this->option('sleep');
        $streamKey = $this->option('stream');

        $this->info("Configuration:");
        $this->info("- Stream: {$streamKey}");
        $this->info("- Batch size: {$batchSize}");
        $this->info("- Sleep: {$sleepTime}s");
        $this->info("- Memory limit: " . ini_get('memory_limit'));

        // Регистрация сигналов
        $this->registerSignalHandlers();

        $processedCount = 0;
        $startTime = microtime(true);
        $emptyCycles = 0;

        try {
            while (!$this->shouldStop) {
                $batchStartTime = microtime(true);

                // Обрабатываем батч
                $processedInBatch = $this->processBatch(
                    $redisAdapter,
                    $processor,
                    $streamKey,
                    $batchSize,
                    (int) $this->option('claim-timeout')
                );

                $processedCount += $processedInBatch;

                // Если событий не было
                if ($processedInBatch === 0) {
                    $emptyCycles++;

                    // Если долго нет событий, увеличиваем sleep
                    $currentSleep = $emptyCycles > 10 ? min($sleepTime * 2, 10) : $sleepTime;

                    if ($this->option('verbose')) {
                        $this->comment("No events, sleeping {$currentSleep}s... (empty cycles: {$emptyCycles})");
                    }

                    // Sleep с проверкой флага остановки
                    for ($i = 0; $i < $currentSleep; $i++) {
                        if ($this->shouldStop) {
                            break 2;
                        }
                        sleep(1);
                        $this->checkForRestart();
                    }
                } else {
                    $emptyCycles = 0;
                    $batchDuration = microtime(true) - $batchStartTime;

                    if ($this->option('verbose')) {
                        $this->info("Processed {$processedInBatch} events in " . round($batchDuration, 2) . "s");
                    }
                }

                // Периодическая статистика
                if ($processedCount > 0 && $processedCount % 1000 === 0) {
                    $this->logStatistics($processedCount, $startTime, $streamKey);
                }

                // Проверка памяти
                if ($this->memoryExceeded(85)) {
                    $this->warn('Memory limit approaching, restarting...');
                    Log::warning('Redis worker memory limit approaching', [
                        'memory_usage' => memory_get_usage(true),
                        'memory_limit' => ini_get('memory_limit'),
                        'processed' => $processedCount,
                    ]);
                    break;
                }

                // Проверка времени работы (restart каждые 4 часа)
                if ((time() - $startTime) > 14400) {
                    $this->info('Maximum uptime reached (4h), restarting...');
                    break;
                }

                $this->checkForRestart();
            }
        } catch (\Throwable $e) {
            $this->error('Worker error: ' . $e->getMessage());
            Log::error('Redis worker failed', [
                'stream' => $streamKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'processed' => $processedCount,
            ]);

            // Ждем перед рестартом
            sleep(5);
            return 1;
        }

        // Graceful shutdown
        $this->info('Shutting down Redis worker gracefully...');

        $totalDuration = microtime(true) - $startTime;
        $rate = $totalDuration > 0 ? round($processedCount / $totalDuration, 2) : 0;

        $this->info("Redis Worker finished. Stats:");
        $this->info("- Total processed: {$processedCount}");
        $this->info("- Total duration: " . round($totalDuration, 2) . "s");
        $this->info("- Average rate: {$rate} events/s");
        $this->info("- Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . "MB");

        Log::info('Redis worker stopped', [
            'stream' => $streamKey,
            'processed' => $processedCount,
            'duration_seconds' => round($totalDuration, 2),
            'rate_per_second' => $rate,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);

        return 0;
    }

    /**
     * Обработка батча событий
     */
    private function processBatch(
        RedisStreamAdapter $redisAdapter,
        EventStreamProcessor $processor,
        string $streamKey,
        int $batchSize,
        int $claimTimeout
    ): int {
        $processed = 0;

        try {
            // Используем метод consume из RedisStreamAdapter
            $redisAdapter->consume($streamKey, function ($event) use ($processor, &$processed) {
                $processor->processEvent($event);
                $processed++;
            }, 1000); // timeout 1 секунда

            // Если мало событий, пытаемся забрать pending
            if ($processed < $batchSize / 2) {
                $claimed = $this->claimPendingMessages(
                    $redisAdapter,
                    $processor,
                    $streamKey,
                    $batchSize - $processed,
                    $claimTimeout
                );
                $processed += $claimed;
            }
        } catch (\Exception $e) {
            Log::error('Batch processing failed', [
                'stream' => $streamKey,
                'error' => $e->getMessage(),
                'batch_size' => $batchSize,
            ]);
            throw $e;
        }

        return $processed;
    }

    /**
     * Забрать зависшие сообщения
     */
    private function claimPendingMessages(
        RedisStreamAdapter $redisAdapter,
        EventStreamProcessor $processor,
        string $streamKey,
        int $limit,
        int $claimTimeout
    ): int {
        // TODO: Эта логика должна быть в RedisStreamAdapter, пока оставляем заглушку
        return 0;
    }

    /**
     * Логирование статистики
     */
    private function logStatistics(int $processedCount, float $startTime, string $streamKey): void
    {
        $duration = microtime(true) - $startTime;
        $rate = round($processedCount / $duration, 2);

        $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
        $memoryLimit = ini_get('memory_limit');

        $this->info("=== Statistics ===");
        $this->info("Total processed: {$processedCount}");
        $this->info("Duration: " . round($duration, 2) . "s");
        $this->info("Rate: {$rate} events/s");
        $this->info("Memory: {$memoryUsage}MB / {$memoryLimit}");

        Log::info('Redis worker statistics', [
            'stream' => $streamKey,
            'total_processed' => $processedCount,
            'duration_seconds' => round($duration, 2),
            'rate_per_second' => $rate,
            'memory_usage_mb' => $memoryUsage,
        ]);
    }

    /**
     * Регистрация обработчиков сигналов
     */
    private function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->shouldStop = true;
                $this->info('Received SIGTERM, shutting down...');
            });

            pcntl_signal(SIGINT, function () {
                $this->shouldStop = true;
                $this->info('Received SIGINT, shutting down...');
            });
        }
    }

    /**
     * Проверка флага перезапуска
     */
    private function checkForRestart(): void
    {
        if (file_exists('/tmp/restart-redis-workers')) {
            $this->info('Restart file detected, shutting down...');
            $this->shouldStop = true;
            @unlink('/tmp/restart-redis-workers');
        }
    }

    /**
     * Проверка превышения лимита памяти
     */
    private function memoryExceeded(int $percentage): bool
    {
        $limit = $this->option('memory') * 1024 * 1024;
        $usage = memory_get_usage(true);

        return ($usage / $limit) * 100 >= $percentage;
    }
}
