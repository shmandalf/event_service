<?php

namespace App\Services\Handlers;

use App\DTO\EventData;
use App\Services\External\PaymentService;
use Illuminate\Support\Facades\Log;

class PurchaseEventHandler
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    public function handle(EventData $event): void
    {
        // Логика обработки покупок
        if ($event->eventType !== 'purchase') {
            return;
        }

        $payload = $event->payload;

        // 1. Валидация данных покупки
        if (!isset($payload['amount'], $payload['currency'])) {
            throw new \InvalidArgumentException('Invalid purchase payload');
        }

        // 2. Логирование покупки
        Log::info('Purchase processed', [
            'user_id' => $event->userId,
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'item_id' => $payload['item_id'] ?? null,
        ]);

        // 3. Интеграция с внешним сервисом (например, отправка в CRM)
        // $this->paymentService->recordPurchase($event);

        // 4. Обновление статистики пользователя
        // $this->updateUserStats($event->userId, $payload['amount']);
    }

    private function updateUserStats(string $userId, float $amount): void
    {
        // Логика обновления статистики
        // Можно использовать Redis для инкремента счетчиков
    }
}
