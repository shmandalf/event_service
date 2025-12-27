<?php

namespace App\Console\Commands;

use App\Services\DeadLetterQueueManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ManageDLQ extends Command
{
    protected $signature = 'dlq:manage
                            {action : Action to perform (stats, restore, cleanup, retry)}
                            {--limit=100 : Limit for restore/cleanup}
                            {--queue=events.dlq : Queue to operate on}
                            {--older-than=7 : Cleanup messages older than X days}';

    protected $description = 'Manage Dead Letter Queue';

    public function handle(DeadLetterQueueManager $dlqManager): int
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'stats':
                return $this->showStats($dlqManager);

            case 'restore':
                return $this->restoreFromBackup($dlqManager);

            case 'cleanup':
                return $this->cleanupOldMessages($dlqManager);

            case 'retry':
                return $this->retryFailedMessages($dlqManager);

            default:
                $this->error("Unknown action: {$action}");
                $this->info("Available actions: stats, restore, cleanup, retry");
                return 1;
        }
    }

    /**
     * Показать статистику DLQ
     */
    private function showStats(DeadLetterQueueManager $dlqManager): int
    {
        $this->info("=== Dead Letter Queue Statistics ===");

        $stats = $dlqManager->getStats();

        $this->table(
            ['Queue', 'Messages', 'Consumers'],
            [
                ['DLQ', $stats['dlq']['message_count'] ?? 0, $stats['dlq']['consumer_count'] ?? 0],
                ['Retry Queue', $stats['retry_queue']['message_count'] ?? 0, $stats['retry_queue']['consumer_count'] ?? 0],
                ['Redis Backup', $stats['redis_backup'] ?? 0, 'N/A'],
            ]
        );

        if (isset($stats['error'])) {
            $this->warn("Error getting stats: " . $stats['error']);
        }

        return 0;
    }

    /**
     * Восстановить сообщения из backup
     */
    private function restoreFromBackup(DeadLetterQueueManager $dlqManager): int
    {
        $limit = (int) $this->option('limit');

        $this->info("Restoring up to {$limit} messages from Redis backup...");

        $restored = 0;
        $batchSize = min($limit, 100);

        $progressBar = $this->output->createProgressBar($limit);

        for ($i = 0; $i < ceil($limit / $batchSize); $i++) {
            $batchRestored = $dlqManager->restoreFromBackup();
            $restored += $batchRestored;

            $progressBar->advance($batchRestored);

            if ($batchRestored === 0) {
                break;
            }

            sleep(1); // Чтобы не перегружать RabbitMQ
        }

        $progressBar->finish();
        $this->newLine();

        if ($restored > 0) {
            $this->info("Successfully restored {$restored} messages from backup.");

            Log::info('DLQ restore completed', [
                'restored_count' => $restored,
                'limit' => $limit,
            ]);
        } else {
            $this->info("No messages to restore from backup.");
        }

        return 0;
    }

    /**
     * Очистить старые сообщения из DLQ
     */
    private function cleanupOldMessages(DeadLetterQueueManager $dlqManager): int
    {
        $queue = $this->option('queue');
        $olderThan = (int) $this->option('older-than');

        $this->warn("WARNING: This will permanently delete messages from DLQ!");
        $this->info("Queue: {$queue}");
        $this->info("Older than: {$olderThan} days");

        if (!$this->confirm('Are you sure you want to proceed?')) {
            $this->info('Cleanup cancelled.');
            return 0;
        }

        // В реальном проекте здесь была бы логика
        // подключения к RabbitMQ Management API или использования
        // rabbitmqadmin для удаления старых сообщений

        $this->info("Cleanup would delete old messages from {$queue}");
        $this->info("Implementation depends on RabbitMQ management API");

        Log::warning('DLQ cleanup requested but not implemented', [
            'queue' => $queue,
            'older_than_days' => $olderThan,
        ]);

        return 0;
    }

    /**
     * Повторно отправить сообщения из DLQ
     */
    private function retryFailedMessages(DeadLetterQueueManager $dlqManager): int
    {
        $this->info("Retrying failed messages from DLQ...");

        // В реальном проекте здесь была бы логика
        // чтения сообщений из DLQ и отправки их обратно
        // в основную очередь для повторной обработки

        $this->info("DLQ retry functionality would require:");
        $this->info("1. Reading messages from DLQ");
        $this->info("2. Resetting retry counters");
        $this->info("3. Sending back to original queue");
        $this->info("4. Handling poison messages (permanent failures)");

        Log::info('DLQ retry requested but not implemented');

        return 0;
    }
}
