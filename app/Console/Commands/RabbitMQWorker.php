<?php

namespace App\Console\Commands;

use App\Services\EventStreamProcessor;
use App\Services\Handlers\RabbitMQEventHandler;
use App\Services\Queues\RabbitMQAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class RabbitMQWorker extends Command
{
    protected $signature = 'rabbitmq:worker
                            {queue=events.high_priority : Queue name}
                            {--prefetch=10 : Prefetch count}
                            {--timeout=30 : Timeout in seconds for graceful shutdown}
                            {--memory=256 : Memory limit in MB}
                            {--restart-file=/tmp/restart-workers : File to trigger restart}';

    protected $description = 'RabbitMQ worker for processing events';

    private bool $shouldStop = false;

    public function handle(RabbitMQAdapter $rabbitMQAdapter, EventStreamProcessor $processor): int
    {
        $this->info('Starting RabbitMQ Worker...');

        // Установка лимитов
        ini_set('memory_limit', $this->option('memory') . 'M');
        set_time_limit(0); // Без лимита времени

        $queue = $this->argument('queue');
        $prefetch = (int) $this->option('prefetch');
        $timeout = (int) $this->option('timeout');

        $this->info("Configuration:");
        $this->info("- Queue: {$queue}");
        $this->info("- Prefetch: {$prefetch}");
        $this->info("- Timeout: {$timeout}s");
        $this->info("- Memory limit: " . ini_get('memory_limit'));

        // Регистрация обработчиков сигналов для graceful shutdown
        $this->registerSignalHandlers();

        // Создаем обработчик событий
        $eventHandler = new RabbitMQEventHandler($processor);

        $processedCount = 0;
        $startTime = microtime(true);

        try {
            // Callback для обработки сообщений
            $callback = function ($event) use ($eventHandler, &$processedCount) {
                $eventHandler->handle($event);
                $processedCount++;

                // Периодически проверяем нужно ли остановиться
                if ($processedCount % 100 === 0) {
                    $this->checkForRestart();

                    // Логируем статистику
                    $duration = microtime(true) - LARAVEL_START;
                    $rate = round($processedCount / $duration, 2);

                    Log::info('RabbitMQ worker stats', [
                        'processed' => $processedCount,
                        'duration_seconds' => round($duration, 2),
                        'rate_per_second' => $rate,
                        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    ]);

                    $this->info("Processed: {$processedCount}, Rate: {$rate}/s");
                }
            };

            $this->info("Waiting for messages on queue '{$queue}'...");

            // Запускаем consumer с обработкой таймаутов
            while (!$this->shouldStop) {
                try {
                    $rabbitMQAdapter->consume($queue, $callback, 1); // timeout 1 секунда

                    // Если мы здесь, значит либо таймаут, либо ошибка
                    if ($this->shouldStop) {
                        break;
                    }

                    // Проверяем нужно ли перезапуститься
                    $this->checkForRestart();

                    // Проверяем использование памяти
                    if ($this->memoryExceeded(90)) {
                        $this->warn('Memory limit approaching, restarting...');
                        break;
                    }
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    // Таймаут - нормально, продолжаем
                    continue;
                } catch (\Exception $e) {
                    $this->error('Consumer error: ' . $e->getMessage());
                    Log::error('RabbitMQ worker error', [
                        'queue' => $queue,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Ждем перед рестартом
                    sleep(5);

                    if ($this->shouldStop) {
                        break;
                    }

                    // Пытаемся продолжить
                    continue;
                }
            }
        } catch (\Throwable $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            Log::critical('RabbitMQ worker fatal error', [
                'queue' => $queue,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }

        // Graceful shutdown
        $this->info('Shutting down gracefully...');

        // Даем время на завершение текущих обработок
        sleep(2);

        $totalDuration = microtime(true) - $startTime;
        $rate = $totalDuration > 0 ? round($processedCount / $totalDuration, 2) : 0;

        $this->info("Worker finished. Stats:");
        $this->info("- Total processed: {$processedCount}");
        $this->info("- Total duration: " . round($totalDuration, 2) . "s");
        $this->info("- Average rate: {$rate} events/s");
        $this->info("- Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . "MB");

        Log::info('RabbitMQ worker stopped', [
            'processed' => $processedCount,
            'duration_seconds' => round($totalDuration, 2),
            'rate_per_second' => $rate,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'reason' => $this->shouldStop ? 'graceful_shutdown' : 'memory_limit',
        ]);

        return 0;
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

            pcntl_signal(SIGHUP, function () {
                $this->shouldStop = true;
                $this->info('Received SIGHUP, shutting down...');
            });
        }

        // Альтернативный способ через файл-флаг
        register_shutdown_function(function () {
            if ($this->shouldStop) {
                $this->info('Shutdown function called');
            }
        });
    }

    /**
     * Проверка флага перезапуска
     */
    private function checkForRestart(): void
    {
        $restartFile = $this->option('restart-file');

        if (file_exists($restartFile)) {
            $this->info('Restart file detected, shutting down...');
            $this->shouldStop = true;

            // Удаляем файл чтобы не триггерить снова
            @unlink($restartFile);
        }

        // Проверка по времени (перезапуск каждые час для предотвращения утечек)
        static $startTime = null;
        if ($startTime === null) {
            $startTime = time();
        }

        $uptime = time() - $startTime;
        if ($uptime > 3600) { // 1 час
            $this->info('Maximum uptime reached, restarting...');
            $this->shouldStop = true;
        }
    }

    /**
     * Проверка превышения лимита памяти
     */
    private function memoryExceeded(int $percentage): bool
    {
        $limit = $this->option('memory') * 1024 * 1024; // MB to bytes
        $usage = memory_get_usage(true);

        return ($usage / $limit) * 100 >= $percentage;
    }
}
