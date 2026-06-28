<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Medoo\Medoo;

class Database
{
    private ?Medoo $connection = null;

    public function getConnection(): Medoo
    {
        if ($this->connection === null) {
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
}
