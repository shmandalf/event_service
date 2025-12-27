<?php

namespace App\Console\Commands;

use App\Services\EventStreamProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessEventStream extends Command
{
    protected $signature = 'events:process
                            {--batch-size=100 : Number of events to process at once}
                            {--sleep=1 : Seconds to sleep when no events}
                            {--timeout=30 : Command timeout in seconds}
                            {--memory=256 : Memory limit in MB}';

    protected $description = 'Process events from Redis Stream';

    public function handle(EventStreamProcessor $processor): int
    {
        $this->info('Starting event stream processor...');

        // Устанавливаем лимиты
        ini_set('memory_limit', $this->option('memory') . 'M');
        set_time_limit($this->option('timeout'));

        $batchSize = (int) $this->option('batch-size');
        $sleepTime = (int) $this->option('sleep');

        $this->info("Batch size: {$batchSize}, Sleep: {$sleepTime}s");

        $processedCount = 0;
        $startTime = microtime(true);

        try {
            while (true) {
                // Проверяем memory usage
                if ($this->memoryExceeded(80)) { // 80% от лимита
                    $this->warn('Memory limit approaching, restarting...');
                    Log::warning('Worker memory limit approaching', [
                        'memory_usage' => memory_get_usage(true),
                        'memory_limit' => ini_get('memory_limit'),
                    ]);
                    break;
                }

                // Обрабатываем батч событий
                $processed = $processor->processBatch($batchSize);
                $processedCount += $processed;

                // Если событий не было, спим
                if ($processed === 0) {
                    if ($this->option('verbose')) {
                        $this->comment('No events, sleeping...');
                    }
                    sleep($sleepTime);

                    // Каждые 10 циклов проверяем сигналы
                    static $cycles = 0;
                    if (++$cycles % 10 === 0 && $this->shouldStop()) {
                        $this->info('Received stop signal, shutting down...');
                        break;
                    }
                } else {
                    $cycles = 0;
                    if ($this->option('verbose')) {
                        $this->info("Processed {$processed} events");
                    }
                }

                // Периодически логируем статистику
                if ($processedCount > 0 && $processedCount % 1000 === 0) {
                    $duration = microtime(true) - $startTime;
                    $rate = round($processedCount / $duration, 2);

                    $this->info("Total processed: {$processedCount}, Rate: {$rate} events/sec");

                    Log::info('Stream processor statistics', [
                        'total_processed' => $processedCount,
                        'duration_seconds' => round($duration, 2),
                        'rate_per_second' => $rate,
                        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->error('Processor error: ' . $e->getMessage());
            Log::error('Stream processor failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Ждем перед рестартом
            sleep(5);
            return 1;
        }

        $duration = microtime(true) - $startTime;
        $this->info("Finished. Processed {$processedCount} events in " . round($duration, 2) . " seconds");

        return 0;
    }

    private function memoryExceeded(int $percentage): bool
    {
        $limit = $this->option('memory') * 1024 * 1024; // MB to bytes
        $usage = memory_get_usage(true);

        return ($usage / $limit) * 100 >= $percentage;
    }

    private function shouldStop(): bool
    {
        // Проверяем файл-флаг для graceful shutdown
        if (file_exists('/tmp/stop-workers')) {
            return true;
        }

        // Проверяем сигналы (для Supervisor)
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return false;
    }
}
