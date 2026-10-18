<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database;
use Psr\Log\LoggerInterface;

class OrderRepository
{
    public function __construct(
        private readonly Database $database,
        private readonly LoggerInterface $logger
    ) {
    }

    public function findByOrderId(string $orderId): ?array
    {
        /** @var array $result */
        $result = $this->database->getConnection()->select('orders', '*', ['order_id' => $orderId]);
        return $result[0] ?? null;
    }

    public function create(array $data): int
    {
        $id = $this->database->upsert('orders', $data);
        $this->logger->debug('Order created', ['id' => $id, 'order_id' => $data['order_id'] ?? null]);
        return $id;
    }

    public function update(string $orderId, array $data): bool
    {
        $data['updated_by'] ??= 'api';
        $changed = $this->database->getConnection()->update('orders', $data, ['order_id' => $orderId])->rowCount() > 0;

        if ($changed) {
            $this->logger->debug('Order updated', ['order_id' => $orderId]);
        }
        return $changed;
    }
}
