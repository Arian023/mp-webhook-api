<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;
use Psr\Log\LoggerInterface;

/**
 * Writes to the webhook_logs table.
 *
 * Every incoming webhook is logged with its full payload, signature
 * validation result, and response code. Useful for debugging missed
 * or duplicate notifications.
 */
class WebhookLogRepository
{
    public function __construct(
        private readonly Database $database,
        private readonly LoggerInterface $logger
    ) {
    }

    public function log(array $data): int
    {
        $this->database->getConnection()->insert('webhook_logs', $data);
        $id = (int) $this->database->getConnection()->id();
        $this->logger->debug('Webhook logged', [
            'id' => $id,
            'event_type' => $data['event_type'] ?? null,
            'data_id' => $data['data_id'] ?? null,
        ]);
        return $id;
    }
}
