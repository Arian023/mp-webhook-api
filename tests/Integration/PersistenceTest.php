<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Infrastructure\Repositories\PaymentRepository;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;

beforeEach(function (): void {
    $this->previousEnv = $_ENV;
    if (getenv('RUN_MYSQL_TESTS') !== '1') {
        $this->markTestSkipped('Set RUN_MYSQL_TESTS=1 to test MySQL/MariaDB with connection-local temporary tables');
    }
    if (is_file(dirname(__DIR__, 2) . '/.env')) {
        (new Dotenv())->loadEnv(dirname(__DIR__, 2) . '/.env');
    }
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        if (getenv($key) !== false) {
            $_ENV[$key] = getenv($key);
        }
    }
    $this->database = new Database();
    $pdo = $this->database->getConnection()->pdo;
    // Never alter application tables: these names shadow them only on this connection.
    $pdo->exec('CREATE TEMPORARY TABLE payments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payment_id BIGINT UNSIGNED NOT NULL UNIQUE, status VARCHAR(30)) ENGINE=InnoDB');
    $pdo->exec('CREATE TEMPORARY TABLE charges (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payment_id INT UNSIGNED NOT NULL, payment_ref BIGINT UNSIGNED NOT NULL, charge_id VARCHAR(30) NOT NULL UNIQUE, amount DECIMAL(12,2)) ENGINE=InnoDB');
});

afterEach(function (): void {
    $_ENV = $this->previousEnv;
    unset($this->database);
});

it('upserts repeated payments and charges without duplicates', function (): void {
    $repo = new PaymentRepository($this->database, new NullLogger());
    $firstId = $repo->create(['payment_id' => 123, 'status' => 'pending']);
    $secondId = $repo->create(['payment_id' => 123, 'status' => 'approved']);
    $repo->insertCharges($firstId, 123, [['charge_id' => 'fee-1', 'amount' => '1.00']]);
    $repo->insertCharges($secondId, 123, [['charge_id' => 'fee-1', 'amount' => '2.00']]);

    expect($secondId)->toBe($firstId);
    expect($repo->findByPaymentId(123)['status'])->toBe('approved');
    $pdo = $this->database->getConnection()->pdo;
    expect((int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn())->toBe(1);
    expect((int) $pdo->query('SELECT COUNT(*) FROM charges')->fetchColumn())->toBe(1);
    expect($pdo->query('SELECT amount FROM charges')->fetchColumn())->toBe('2.00');
});

it('rolls back all writes after a failed charge insert', function (): void {
    $repo = new PaymentRepository($this->database, new NullLogger());
    $this->database->beginTransaction();
    try {
        $id = $repo->create(['payment_id' => 456, 'status' => 'approved']);
        $repo->insertCharges($id, 456, [['charge_id' => null, 'amount' => '1.00']]); // NOT NULL
        $this->fail('Expected the database to reject the charge');
    } catch (PDOException) {
        $this->database->rollback();
    }
    expect($this->database->inTransaction())->toBeFalse();
    expect($repo->findByPaymentId(456))->toBeNull();
});
