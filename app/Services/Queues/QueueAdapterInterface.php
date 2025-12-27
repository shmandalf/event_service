<?php

namespace App\Services\Queues;

use App\DTO\EventData;

interface QueueAdapterInterface
{
    /**
     * Отправить событие в очередь
     */
    public function push(EventData $event, int $priority = 0): string;

    /**
     * Потреблять сообщения из очереди
     */
    public function consume(string $queue, callable $handler, int $timeout = 0): void;

    /**
     * Получить статистику очереди
     */
    public function getQueueStats(string $queue): array;

    /**
     * Отключиться от брокера
     */
    public function disconnect(): void;
}
