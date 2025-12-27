<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventQueueService;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    public function __construct(
        private EventQueueService $queueService
    ) {}

    /**
     * Получить информацию о системе
     */
    public function info(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'system' => $this->queueService->getSystemInfo(),
        ]);
    }

    /**
     * Получить статистику очередей
     */
    public function queueStats(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'queue_stats' => $this->queueService->getQueueStats(),
        ]);
    }

    /**
     * Получить статистику circuit breakers
     */
    public function circuitBreakerStats(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'circuit_breakers' => $this->queueService->getCircuitBreakerStats(),
        ]);
    }

    /**
     * Проверить здоровье системы
     */
    public function health(): JsonResponse
    {
        $checks = [
            'rabbitmq' => $this->checkRabbitMQ(),
            'redis' => $this->checkRedis(),
            'database' => $this->checkDatabase(),
            'circuit_breakers' => $this->checkCircuitBreakers(),
        ];

        $allHealthy = !in_array(false, $checks, true);

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toISOString(),
            'checks' => $checks,
        ], $allHealthy ? 200 : 503);
    }

    private function checkRabbitMQ(): bool
    {
        try {
            $adapter = app('queue.adapter.rabbitmq');
            $stats = $adapter->getQueueStats('events.high_priority');
            return !isset($stats['error']);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            return \Illuminate\Support\Facades\Redis::ping() === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkDatabase(): bool
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkCircuitBreakers(): array
    {
        $stats = $this->queueService->getCircuitBreakerStats();

        return [
            'rabbitmq' => $stats['rabbitmq']['is_available'] ?? false,
            'redis' => $stats['redis']['is_available'] ?? false,
        ];
    }
}
