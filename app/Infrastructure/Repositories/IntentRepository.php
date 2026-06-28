<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;

class IntentRepository
{
    public function __construct(private Database $database) {}

    public function findByIntentId(string $intentId): ?array
    {
        return $this->database->getConnection()->select('intents', '*', ['intent_id' => $intentId])->fetch() ?: null;
    }

    public function create(array $data): int
    {
        return (int) $this->database->getConnection()->insert('intents', $data)->rowCount();
    }

    public function updateStatus(string $intentId, string $status): bool
    {
        return $this->database->getConnection()->update('intents', ['intent_status' => $status], ['intent_id' => $intentId])->rowCount() > 0;
    }

    public function updateWithPayment(string $intentId, array $paymentData): bool
    {
        return $this->database->getConnection()->update('intents', $paymentData, ['intent_id' => $intentId])->rowCount() > 0;
    }
}
