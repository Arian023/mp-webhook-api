<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;

class TerminalRepository
{
    public function __construct(private Database $database) {}

    public function findByExternalId(string $externalId): ?array
    {
        return $this->database->getConnection()->select('terminals', '*', ['external_id' => $externalId])->fetch() ?: null;
    }

    public function findByPosId(string $posId): ?array
    {
        return $this->database->getConnection()->select('terminals', '*', ['pos_id' => $posId])->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        return $this->database->getConnection()->select('terminals', '*', ['id' => $id])->fetch() ?: null;
    }
}
