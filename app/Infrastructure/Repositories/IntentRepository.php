<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;
use Psr\Log\LoggerInterface;

class IntentRepository
{
    public function __construct(
        private readonly Database $database,
        private readonly LoggerInterface $logger
    ) {
    }

    public function findByIntentId(string $intentId): ?array
    {
        /** @var array|null $result */
        $result = $this->database->getConnection()->select('intents', null, '*', ['intent_id' => $intentId]);
        return $result[0] ?? null;
    }

    public function create(array $data): int
    {
        $this->database->getConnection()->insert('intents', $data);
        $id = (int) $this->database->getConnection()->id();
        $this->logger->debug('Intent created', ['id' => $id, 'intent_id' => $data['intent_id'] ?? null]);
        return $id;
    }

    public function updateStatus(string $intentId, string $status): bool
    {
        $changed = $this->database->getConnection()->update('intents', [
            'intent_status' => $status,
            'updated_by' => 'api',
        ], ['intent_id' => $intentId])->rowCount() > 0;

        if ($changed) {
            $this->logger->debug('Intent status updated', ['intent_id' => $intentId, 'status' => $status]);
        }
        return $changed;
    }

    public function updateWithPayment(string $intentId, array $paymentData): bool
    {
        $paymentData['updated_by'] = 'api';
        $changed = $this->database->getConnection()->update('intents', $paymentData, ['intent_id' => $intentId])->rowCount() > 0;

        if ($changed) {
            $this->logger->debug('Intent updated with payment', [
                'intent_id' => $intentId,
                'payment_id' => $paymentData['payment_id'] ?? null,
            ]);
        }
        return $changed;
    }
}
