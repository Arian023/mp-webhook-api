<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\OrderRepository;
use Psr\Log\LoggerInterface;

class OrderService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MercadoPagoClient $mpClient,
        private readonly OrderRepository $orderRepository,
    ) {
    }

    public function create(array $body, string $idempotencyKey): array
    {
        $mpBody = [
            'type' => 'point',
            'external_reference' => $body['external_reference'] ?? '',
            'transactions' => [
                'payments' => [
                    ['amount' => (string) ($body['amount'] ?? 0)],
                ],
            ],
            'config' => [
                'point' => [
                    'terminal_id' => $body['device_id'] ?? '',
                    'print_on_terminal' => $body['print_on_terminal'] ?? 'no_ticket',
                ],
            ],
            'description' => $body['description'] ?? 'Point order',
        ];

        if (isset($body['ticket_number'])) {
            $mpBody['config']['point']['ticket_number'] = $body['ticket_number'];
        }

        if (isset($body['expiration_time'])) {
            $mpBody['expiration_time'] = $body['expiration_time'];
        }

        $response = $this->mpClient->createOrder($mpBody, $idempotencyKey);

        $this->logger->info('Order created via API', [
            'order_id' => $response['id'] ?? null,
        ]);

        return $response;
    }

    public function get(string $orderId): array
    {
        $response = $this->mpClient->getOrder($orderId);

        $existing = $this->orderRepository->findByOrderId($orderId);
        if ($existing !== null) {
            $this->orderRepository->update($orderId, [
                'status' => $response['status'] ?? $existing['status'],
                'status_detail' => $response['status_detail'] ?? null,
            ]);
        }

        return $response;
    }

    public function cancel(string $orderId, string $idempotencyKey): array
    {
        $response = $this->mpClient->cancelOrder($orderId, $idempotencyKey);

        $this->orderRepository->update($orderId, [
            'status' => $response['status'] ?? 'canceled',
            'status_detail' => $response['status_detail'] ?? 'canceled',
            'action' => 'order.canceled',
        ]);

        $this->logger->info('Order canceled', ['order_id' => $orderId]);

        return $response;
    }

    public function refund(string $orderId, ?string $transactionId, string $idempotencyKey): array
    {
        $body = $transactionId !== null ? ['transactions' => [['id' => $transactionId]]] : null;
        $response = $this->mpClient->refundOrder($orderId, $body, $idempotencyKey);

        $this->orderRepository->update($orderId, [
            'status' => $response['status'] ?? 'refunded',
            'status_detail' => $response['status_detail'] ?? 'refunded',
            'action' => 'order.refunded',
        ]);

        $this->logger->info('Order refunded', ['order_id' => $orderId]);

        return $response;
    }
}
