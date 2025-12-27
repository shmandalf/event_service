<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Аналитические события
    Route::prefix('events')->group(function () {
        Route::post('/', [EventController::class, 'store'])
            ->name('events.store');

        Route::get('/{eventId}/status', [EventController::class, 'status'])
            ->name('events.status');
    });

    // Prometheus метрики
    Route::get('/metrics', [MetricsController::class, 'export']);

    // Статистика
    Route::get('/stats', [MetricsController::class, 'stats']);

    // Системные endpoints
    Route::prefix('system')->group(function () {
        Route::get('/info', [SystemController::class, 'info']);
        Route::get('/queue-stats', [SystemController::class, 'queueStats']);
        Route::get('/circuit-breakers', [SystemController::class, 'circuitBreakerStats']);
        Route::get('/health', [SystemController::class, 'health']);
    });

    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'services' => [
                'redis' => \Illuminate\Support\Facades\Redis::ping() === true,
                'database' => \Illuminate\Support\Facades\DB::connection()->getPdo() !== null,
                'queue' => true, // TODO: добавить проверку очереди
            ],
        ]);
    });
});
