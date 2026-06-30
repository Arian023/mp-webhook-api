<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;
use Psr\Log\LoggerInterface;

/**
 * CRUD for the payments table + charge inserts.
 *
 * Stores the full payment record from the MP API (card details, amounts,
 * timestamps) and the nested charges_details[] breakdown (fees + taxes).
 */
class PaymentRepository
{
    public function __construct(
        private readonly Database $database,
        private readonly LoggerInterface $logger
    ) {
    }

    public function findByPaymentId(int $paymentId): ?array
    {
        /** @var array $result */
        $result = $this->database->getConnection()->select('payments', '*', ['payment_id' => $paymentId]);
        return $result[0] ?? null;
    }

    public function create(array $data): int
    {
        $this->database->getConnection()->insert('payments', $data);
        $id = (int) $this->database->getConnection()->id();
        $this->logger->debug('Payment created', ['id' => $id, 'payment_id' => $data['payment_id'] ?? null]);
        return $id;
    }

    public function update(int $paymentId, array $data): bool
    {
        $data['updated_by'] = 'api';
        $changed = $this->database->getConnection()->update('payments', $data, ['payment_id' => $paymentId])->rowCount() > 0;

        if ($changed) {
            $this->logger->debug('Payment updated', ['payment_id' => $paymentId]);
        }
        return $changed;
    }

    public function insertCharges(int $paymentInternalId, int $paymentRef, array $charges): void
    {
        foreach ($charges as $charge) {
            $this->database->getConnection()->insert('charges', array_merge($charge, [
                'payment_id' => $paymentInternalId,
                'payment_ref' => $paymentRef,
            ]));
        }
        $this->logger->debug('Charges inserted', [
            'payment_id' => $paymentInternalId,
            'count' => count($charges),
        ]);
    }
}
