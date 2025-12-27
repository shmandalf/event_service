<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\EventData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EventValidator
{
    private array $rules = [
        'user_id' => 'required|uuid',
        'event_type' => 'required|string|max:50|in:click,view,purchase,login,logout,signup,subscription,payment,custom',
        'timestamp' => 'required|date|before_or_equal:now',
        'payload' => 'required|array',
        'payload.item_id' => 'sometimes|string',
        'payload.amount' => 'sometimes|numeric|min:0',
        'payload.currency' => 'sometimes|string|size:3',
        'metadata' => 'sometimes|array',
        'metadata.app_version' => 'sometimes|string',
        'metadata.platform' => 'sometimes|string|in:ios,android,web',
        'idempotency_key' => 'sometimes|string|max:64',
        'priority' => 'sometimes|integer|min:0|max:10',
    ];

    /**
     * @throws ValidationException
     */
    public function validate(array $data): EventData
    {
        $validator = Validator::make($data, $this->rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Дополнительная бизнес-валидация
        $this->validateBusinessRules($data);

        return EventData::fromArray($data);
    }

    /**
     * Бизнес валидация
     *
     * @param array $data
     */
    private function validateBusinessRules(array $data): void
    {
        // Например, проверка что purchase имеет amount
        if ($data['event_type'] === 'purchase') {
            if (!isset($data['payload']['amount']) || !is_numeric($data['payload']['amount'])) {
                throw ValidationException::withMessages([
                    'payload.amount' => 'Amount is required for purchase events',
                ]);
            }

            if ($data['payload']['amount'] <= 0) {
                throw ValidationException::withMessages([
                    'payload.amount' => 'Amount must be positive for purchase events',
                ]);
            }
        }

        // Проверка idempotency key формата
        if (isset($data['idempotency_key']) && !preg_match('/^[a-f0-9]{64}$/i', $data['idempotency_key'])) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Idempotency key must be a 64-character hex string',
            ]);
        }
    }
}
