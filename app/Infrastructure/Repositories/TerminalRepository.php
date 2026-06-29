<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;

/**
 * Reads from the terminals table.
 *
 * Terminals are Point devices registered in MP, identified by external_id
 * (e.g. "PAX_A910__SMARTPOS1495319707") or pos_id. Used to resolve the
 * internal terminal_id FK when storing payments.
 */
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
