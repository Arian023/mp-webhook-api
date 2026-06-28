<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;

class WebhookLogRepository
{
    public function __construct(private Database $database) {}

    public function log(array $data): int
    {
        return (int) $this->database->getConnection()->insert('webhook_logs', $data)->rowCount();
    }
}
