<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;

class PaymentRepository
{
    public function __construct(private Database $database) {}

    public function findByPaymentId(int $paymentId): ?array
    {
        return $this->database->getConnection()->select('payments', '*', ['payment_id' => $paymentId])->fetch() ?: null;
    }

    public function create(array $data): int
    {
        return (int) $this->database->getConnection()->insert('payments', $data)->rowCount();
    }

    public function update(int $paymentId, array $data): bool
    {
        return $this->database->getConnection()->update('payments', $data, ['payment_id' => $paymentId])->rowCount() > 0;
    }

    public function insertCharges(int $paymentInternalId, int $paymentRef, array $charges): void
    {
        foreach ($charges as $charge) {
            $this->database->getConnection()->insert('charges', array_merge($charge, [
                'payment_id' => $paymentInternalId,
                'payment_ref' => $paymentRef,
            ]));
        }
    }
}
