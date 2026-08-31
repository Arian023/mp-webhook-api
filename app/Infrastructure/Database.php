<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Medoo\Medoo;

/**
 * Lazy Medoo connection factory.
 *
 * Reads DB config from env vars and creates the connection on first use.
 * Registered as a singleton in the PHP-DI container so all repositories
 * share the same underlying PDO connection.
 */
class Database
{
    private ?Medoo $connection = null;

    public function getConnection(): Medoo
    {
        if (!$this->connection instanceof Medoo) {
            $this->connection = new Medoo([
                'type' => 'mysql',
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
                'database' => $_ENV['DB_NAME'] ?? 'mp_webhook',
                'username' => $_ENV['DB_USER'] ?? 'root',
                'password' => $_ENV['DB_PASS'] ?? '',
                'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            ]);
        }

        return $this->connection;
    }

    public function beginTransaction(): void
    {
        $this->getConnection()->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->getConnection()->pdo->commit();
    }

    public function rollback(): void
    {
        $this->getConnection()->pdo->rollBack();
    }
}
