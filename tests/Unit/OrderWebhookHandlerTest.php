<?php

declare(strict_types=1);

use App\Application\Services\OrderWebhookHandler;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\MercadoPago\MercadoPagoException;
use App\Infrastructure\Repositories\OrderRepository;
use App\Infrastructure\Repositories\PaymentRepository;
use App\Infrastructure\Repositories\TerminalRepository;
use Psr\Log\NullLogger;

function orderFromMp(array $overrides = []): array
{
    return array_merge([
        'id' => 'ORD-TEST-001',
        'status' => 'paid',
        'status_detail' => 'accredited',
        'total_amount' => 1500.00,
        'external_reference' => 'REF-001',
        'description' => 'Test order',
        'live_mode' => false,
        'created_date' => '2026-06-30T10:00:00.000-04:00',
        'config' => [
            'point' => [
                'terminal_id' => 'DEVICE-001',
                'ticket_number' => 'TK-001',
                'print_on_terminal' => 'no_ticket',
            ],
        ],
        'transactions' => [
            'payments' => [
                [
                    'id' => '12345678',
                    'reference_id' => '87654321',
                    'status' => 'processed',
                    'status_detail' => 'accredited',
                    'amount' => 1500.00,
                    'payment_method' => [
                        'id' => 'visa',
                        'type' => 'credit_card',
                        'installments' => 1,
                    ],
                ],
            ],
        ],
    ], $overrides);
}

function buildHandler(
    MercadoPagoClient $mpClient,
    OrderRepository $orderRepo,
    ?PaymentRepository $paymentRepo = null,
    ?TerminalRepository $terminalRepo = null,
): OrderWebhookHandler {
    return new OrderWebhookHandler(
        new NullLogger(),
        $mpClient,
        $orderRepo,
        $paymentRepo ?? new class () extends PaymentRepository {
            public function __construct()
            {
            }
        },
        $terminalRepo ?? new class () extends TerminalRepository {
            public function __construct()
            {
            }
        },
    );
}

it('creates new order from webhook when not found locally', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')->willReturn(orderFromMp());

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('findByOrderId')->willReturn(null);
    $orderRepo->expects($this->once())
        ->method('create')
        ->with($this->callback(
            fn (array $data) =>
            $data['order_id'] === 'ORD-TEST-001'
            && $data['status'] === 'paid'
            && $data['amount'] === 1500.00
            && $data['device_id'] === 'DEVICE-001'
            && $data['updated_by'] === 'webhook'
        ));

    $handler = buildHandler(
        $mpClient,
        $orderRepo,
        $this->createMock(PaymentRepository::class),
        $this->createMock(TerminalRepository::class),
    );
    $handler->handle(['action' => 'order.created'], 'ORD-TEST-001');
});

it('updates existing order from webhook', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')->willReturn(orderFromMp(['status' => 'canceled']));

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('findByOrderId')->willReturn(['id' => 1, 'status' => 'paid']);
    $orderRepo->expects($this->once())
        ->method('update')
        ->with('ORD-TEST-001', $this->callback(
            fn (array $data) =>
            $data['status'] === 'canceled'
            && $data['action'] === 'order.updated'
            && $data['updated_by'] === 'webhook'
        ));

    $handler = buildHandler(
        $mpClient,
        $orderRepo,
        $this->createMock(PaymentRepository::class),
        $this->createMock(TerminalRepository::class),
    );
    $handler->handle(['action' => 'order.updated'], 'ORD-TEST-001');
});

it('syncs new payment from order webhook', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')->willReturn(orderFromMp());

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('findByOrderId')->willReturn(['id' => 1, 'status' => 'paid']);

    $paymentRepo = $this->createMock(PaymentRepository::class);
    $paymentRepo->method('findByPaymentId')->willReturn(null);
    $paymentRepo->expects($this->once())
        ->method('create')
        ->with($this->callback(
            fn (array $data) =>
            $data['payment_id'] === 87654321
            && $data['status'] === 'processed'
            && $data['payment_method'] === 'visa'
            && $data['total_amount'] === 1500.00
            && $data['updated_by'] === 'webhook'
        ));

    $terminalRepo = $this->createMock(TerminalRepository::class);
    $terminalRepo->method('findByExternalId')->willReturn(['id' => 42]);

    $handler = buildHandler($mpClient, $orderRepo, $paymentRepo, $terminalRepo);
    $handler->handle(['action' => 'order.updated'], 'ORD-TEST-001');
});

it('updates existing payment from order webhook', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')->willReturn(orderFromMp());

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('findByOrderId')->willReturn(['id' => 1, 'status' => 'created']);

    $paymentRepo = $this->createMock(PaymentRepository::class);
    $paymentRepo->method('findByPaymentId')->willReturn([
        'id' => 10,
        'payment_id' => 87654321,
        'approved_at' => null,
    ]);
    $paymentRepo->expects($this->once())
        ->method('update')
        ->with(87654321, $this->callback(
            fn (array $data) =>
            $data['status'] === 'processed'
            && $data['updated_by'] === 'webhook'
            && isset($data['approved_at'])
        ));

    $terminalRepo = $this->createMock(TerminalRepository::class);

    $handler = buildHandler($mpClient, $orderRepo, $paymentRepo, $terminalRepo);
    $handler->handle(['action' => 'order.updated'], 'ORD-TEST-001');
});

it('handles order with no payments gracefully', function (): void {
    $orderData = orderFromMp();
    $orderData['transactions']['payments'] = [];

    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')->willReturn($orderData);

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('findByOrderId')->willReturn(null);
    $orderRepo->method('create')->willReturn(1);

    $paymentRepo = $this->createMock(PaymentRepository::class);
    $paymentRepo->expects($this->never())->method('create');

    $handler = buildHandler($mpClient, $orderRepo, $paymentRepo);
    $handler->handle(['action' => 'order.created'], 'ORD-TEST-001');
});

it('skips payment with non-numeric reference_id', function (): void {
    $orderData = orderFromMp();
    $orderData['transactions']['payments'][0]['reference_id'] = 'not-a-number';

    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')->willReturn($orderData);

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('findByOrderId')->willReturn(['id' => 1, 'status' => 'created']);

    $paymentRepo = $this->createMock(PaymentRepository::class);
    $paymentRepo->expects($this->never())->method('create');

    $handler = buildHandler($mpClient, $orderRepo, $paymentRepo);
    $handler->handle(['action' => 'order.updated'], 'ORD-TEST-001');
});

it('returns early when MP API fails to fetch order', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')
        ->willThrowException(new MercadoPagoException('API error'));

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->expects($this->never())->method('create');
    $orderRepo->expects($this->never())->method('update');

    $handler = buildHandler($mpClient, $orderRepo);
    $handler->handle(['action' => 'order.created'], 'ORD-FAIL');

    $this->expectNotToPerformAssertions();
});
