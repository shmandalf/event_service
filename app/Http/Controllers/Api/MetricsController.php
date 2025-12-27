<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetricsService;
use App\Services\EventQueueService;
use App\Services\EventStreamProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class MetricsController extends Controller
{
    public function __construct(
        private MetricsService $metrics,
        private EventQueueService $queueService,
        private EventStreamProcessor $streamProcessor
    ) {}

    /**
     * Экспорт метрик в формате Prometheus
     */
    public function export(): \Illuminate\Http\Response
    {
        // 1. Собираем бизнес-метрики из сервиса
        $businessMetrics = $this->metrics->render();

        // 2. Добавляем системные метрики
        $systemMetrics = $this->collectSystemMetrics();

        // 3. Добавляем метрики очереди
        $queueMetrics = $this->collectQueueMetrics();

        // 4. Добавляем метрики базы данных
        $dbMetrics = $this->collectDatabaseMetrics();

        $content = implode("\n", [
            $businessMetrics,
            $systemMetrics,
            $queueMetrics,
            $dbMetrics,
        ]);

        return response($content)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    /**
     * Системные метрики
     */
    private function collectSystemMetrics(): string
    {
        $metrics = [];

        // PHP метрики
        $metrics[] = '# HELP php_memory_usage_bytes PHP memory usage';
        $metrics[] = '# TYPE php_memory_usage_bytes gauge';
        $metrics[] = 'php_memory_usage_bytes ' . memory_get_usage(true);

        $metrics[] = '# HELP php_memory_peak_bytes PHP memory peak usage';
        $metrics[] = '# TYPE php_memory_peak_bytes gauge';
        $metrics[] = 'php_memory_peak_bytes ' . memory_get_peak_usage(true);

        // Время работы
        $metrics[] = '# HELP php_uptime_seconds PHP process uptime';
        $metrics[] = '# TYPE php_uptime_seconds gauge';
        $metrics[] = 'php_uptime_seconds ' . (microtime(true) - LARAVEL_START);

        // Загруженность
        $load = sys_getloadavg();
        $metrics[] = '# HELP system_load_average System load average';
        $metrics[] = '# TYPE system_load_average gauge';
        $metrics[] = 'system_load_average{period="1min"} ' . ($load[0] ?? 0);
        $metrics[] = 'system_load_average{period="5min"} ' . ($load[1] ?? 0);
        $metrics[] = 'system_load_average{period="15min"} ' . ($load[2] ?? 0);

        return implode("\n", $metrics);
    }

    /**
     * Метрики очередей
     */
    private function collectQueueMetrics(): string
    {
        $metrics = [];

        try {
            // Redis Stream метрики
            // @TODO: disabled for now
            // $stats = $this->streamProcessor->getStats();
            /*
            foreach ($stats as $stream => $data) {
                if (is_array($data)) {
                    $metrics[] = '# HELP redis_stream_length Redis stream length';
                    $metrics[] = '# TYPE redis_stream_length gauge';
                    $metrics[] = 'redis_stream_length{stream="' . $stream . '"} ' . ($data['length'] ?? 0);

                    $metrics[] = '# HELP redis_stream_pending Pending messages in stream';
                    $metrics[] = '# TYPE redis_stream_pending gauge';
                    $metrics[] = 'redis_stream_pending{stream="' . $stream . '"} ' . ($data['pending'] ?? 0);
                } else {
                    // DLQ length
                    $metrics[] = '# HELP redis_dlq_length Dead letter queue length';
                    $metrics[] = '# TYPE redis_dlq_length gauge';
                    $metrics[] = 'redis_dlq_length ' . $data;
                }
            }
            */

            // Очередь Laravel
            $laravelQueueSize = Redis::llen('queues:default');
            $metrics[] = '# HELP laravel_queue_size Laravel queue size';
            $metrics[] = '# TYPE laravel_queue_size gauge';
            $metrics[] = 'laravel_queue_size{queue="default"} ' . $laravelQueueSize;

            $highPrioritySize = Redis::llen('queues:high_priority');
            $metrics[] = 'laravel_queue_size{queue="high_priority"} ' . $highPrioritySize;
        } catch (\Exception $e) {
            // Если Redis недоступен, возвращаем метрику ошибки
            $metrics[] = '# HELP redis_connection_status Redis connection status';
            $metrics[] = '# TYPE redis_connection_status gauge';
            $metrics[] = 'redis_connection_status 0';
        }

        return implode("\n", $metrics);
    }

    /**
     * Метрики базы данных
     */
    private function collectDatabaseMetrics(): string
    {
        $metrics = [];

        try {
            // Количество событий по статусам
            $statusCounts = DB::table('events')
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get();

            $metrics[] = '# HELP events_by_status Events count by status';
            $metrics[] = '# TYPE events_by_status gauge';

            foreach ($statusCounts as $row) {
                $metrics[] = 'events_by_status{status="' . $row->status . '"} ' . $row->count;
            }

            // Количество событий по типам (за последний час)
            $typeCounts = DB::table('events')
                ->select('event_type', DB::raw('count(*) as count'))
                ->where('created_at', '>=', now()->subHour())
                ->groupBy('event_type')
                ->get();

            $metrics[] = '# HELP events_by_type Events count by type (last hour)';
            $metrics[] = '# TYPE events_by_type gauge';

            foreach ($typeCounts as $row) {
                $metrics[] = 'events_by_type{type="' . $row->event_type . '"} ' . $row->count;
            }

            // Размер таблицы
            $tableSize = DB::selectOne("
                SELECT
                    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'events'
            ");

            $metrics[] = '# HELP database_table_size_mb Database table size in MB';
            $metrics[] = '# TYPE database_table_size_mb gauge';
            $metrics[] = 'database_table_size_mb{table="events"} ' . ($tableSize->size_mb ?? 0);

            // Connection status
            $metrics[] = '# HELP database_connection_status Database connection status';
            $metrics[] = '# TYPE database_connection_status gauge';
            $metrics[] = 'database_connection_status 1';
        } catch (\Exception $e) {
            $metrics[] = '# HELP database_connection_status Database connection status';
            $metrics[] = '# TYPE database_connection_status gauge';
            $metrics[] = 'database_connection_status 0';
        }

        return implode("\n", $metrics);
    }

    /**
     * Health check endpoint
     */
    public function health(): \Illuminate\Http\JsonResponse
    {
        $checks = [
            'api' => true,
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];

        $allHealthy = !in_array(false, $checks, true);

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toISOString(),
            'checks' => $checks,
        ], $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            return Redis::ping() === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkQueue(): bool
    {
        try {
            // Простая проверка - пытаемся записать и прочитать тестовое значение
            $testKey = 'health_check:' . time();
            Redis::setex($testKey, 10, 'test');
            $value = Redis::get($testKey);
            Redis::del($testKey);

            return $value === 'test';
        } catch (\Exception $e) {
            return false;
        }
    }
}
