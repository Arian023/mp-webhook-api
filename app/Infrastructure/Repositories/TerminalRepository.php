<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;

class TerminalRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function findByExternalId(string $externalId): ?array
    {
        /** @var array|null $result */
        $result = $this->database->getConnection()->select('terminals', null, '*', ['external_id' => $externalId]);
        return $result[0] ?? null;
    }

    public function findByPosId(string $posId): ?array
    {
        /** @var array|null $result */
        $result = $this->database->getConnection()->select('terminals', null, '*', ['pos_id' => $posId]);
        return $result[0] ?? null;
    }

    public function findById(int $id): ?array
    {
        /** @var array|null $result */
        $result = $this->database->getConnection()->select('terminals', null, '*', ['id' => $id]);
        return $result[0] ?? null;
    }
}
