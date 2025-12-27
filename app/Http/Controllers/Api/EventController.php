<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventQueueService;
use App\Services\EventValidator;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function __construct(
        private EventValidator $validator,
        private EventQueueService $queue,
        private MetricsService $metrics,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        try {
            // Обогащаем данные метаданными
            $data = $request->all();
            $data['_ip'] = $request->ip();
            $data['_user_agent'] = $request->userAgent();

            // Валидация
            $eventData = $this->validator->validate($data);

            // Проверка идемпотентности (опционально)
            if ($eventData->idempotencyKey) {
                $existing = $this->checkIdempotency($eventData->idempotencyKey);
                if ($existing) {
                    return response()->json([
                        'success' => true,
                        'event_id' => $existing,
                        'message' => 'Event already processed',
                        'cached' => true,
                    ], 200);
                }
            }

            // 5. Отправка в очередь
            $messageId = $this->queue->push($eventData);

            // 6. Сохранение идемпотентного ключа
            if ($eventData->idempotencyKey) {
                $this->saveIdempotencyKey($eventData->idempotencyKey, $eventData->id);
            }

            // 7. Метрики
            $duration = microtime(true) - $startTime;
            $this->metrics->histogram('api_request_duration_seconds', $duration, ['endpoint' => 'events.store']);

            Log::info('Event accepted', [
                'event_id' => $eventData->id,
                'type' => $eventData->eventType,
                'priority' => $eventData->priority,
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return response()->json([
                'success' => true,
                'event_id' => $eventData->id,
                'message' => 'Event queued for processing',
                'queue_message_id' => $messageId,
            ], 202);
        } catch (ValidationException $e) {
            $this->metrics->increment('api_validation_errors_total');

            return response()->json([
                'error' => 'Validation failed',
                'messages' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            $this->metrics->increment('api_errors_total');
            Log::error('API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => 'Event could not be processed',
            ], 500);
        }
    }

    private function checkIdempotency(string $key): ?string
    {
        return \Illuminate\Support\Facades\Redis::get("idempotency:$key");
    }

    private function saveIdempotencyKey(string $key, string $eventId, int $ttl = 86400): void
    {
        \Illuminate\Support\Facades\Redis::setex("idempotency:$key", $ttl, $eventId);
    }

    /**
     * Статус обработки события
     */
    public function status(string $eventId): JsonResponse
    {
        // Реализация проверки статуса
        return response()->json([
            'event_id' => $eventId,
            'status' => 'queued', // или 'processing', 'processed', 'failed'
            'estimated_time' => null,
        ]);
    }
}
