<?php

namespace App\Console\Commands;

use App\Services\EventQueueService;
use App\Services\EventStreamProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ConsumeRabbitMQ extends Command
{
    protected $signature = 'rabbitmq:consume
                            {queue=high_priority : Queue to consume}
                            {--timeout=0 : Timeout in seconds}
                            {--prefetch=10 : Prefetch count}';

    protected $description = 'Consume events from RabbitMQ';

    public function handle(EventQueueService $queueService, EventStreamProcessor $processor): int
    {
        $queue = $this->argument('queue');
        $timeout = (int) $this->option('timeout');

        $this->info("Starting RabbitMQ consumer for queue: {$queue}");

        $handler = function ($event) use ($processor, $queue) {
            $startTime = microtime(true);

            try {
                // Обрабатываем событие
                $processor->processEvent($event);

                $duration = microtime(true) - $startTime;
                Log::debug('RabbitMQ event processed', [
                    'event_id' => $event->id,
                    'queue' => $queue,
                    'processing_time_ms' => round($duration * 1000, 2),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to process RabbitMQ event', [
                    'event_id' => $event->id,
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        };

        try {
            if ($queue === 'high_priority') {
                $queueService->startHighPriorityConsumer($handler);
            } else {
                $queueService->startNormalPriorityConsumer($handler);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Consumer error: ' . $e->getMessage());
            Log::error('RabbitMQ consumer failed', [
                'queue' => $queue,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }
}
