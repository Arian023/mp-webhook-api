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
                'error' => \PDO::ERRMODE_EXCEPTION,
                'option' => [\PDO::ATTR_EMULATE_PREPARES => false],
            ]);
        }

        return $this->connection;
    }

    public function beginTransaction(): void
    {
        $this->getConnection()->pdo->beginTransaction();
    }

    /** Atomic MySQL/MariaDB insert/update using the schema's unique business keys. */
    public function upsert(string $table, array $data): int
    {
        if (!in_array($table, ['payments', 'intents', 'orders', 'charges'], true) || $data === []) {
            throw new \InvalidArgumentException('Unsupported upsert');
        }
        $columns = array_keys($data);
        foreach ($columns as $column) {
            if (!is_string($column) || !preg_match('/^[a-z_]+$/D', $column)) {
                throw new \InvalidArgumentException('Invalid column');
            }
        }
        $names = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $updates = implode(', ', array_map(static fn (string $column): string => "`{$column}` = VALUES(`{$column}`)", $columns));
        $pdo = $this->getConnection()->pdo;
        $statement = $pdo->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), {$updates}");
        $statement->execute(array_values($data));

        return (int) $pdo->lastInsertId();
    }

    public function commit(): void
    {
        $this->getConnection()->pdo->commit();
    }

    public function inTransaction(): bool
    {
        return $this->getConnection()->pdo->inTransaction();
    }

    public function rollback(): void
    {
        $this->getConnection()->pdo->rollBack();
    }
}
