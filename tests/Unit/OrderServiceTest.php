<?php

declare(strict_types=1);

use App\Application\Services\OrderService;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\OrderRepository;
use Psr\Log\NullLogger;

it('creates an order via MP API', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->expects($this->once())
        ->method('createOrder')
        ->with($this->callback(
            fn (array $body) =>
            $body['type'] === 'point'
            && $body['transactions']['payments'][0]['amount'] === '1500'
            && $body['config']['point']['terminal_id'] === 'DEVICE-001'
        ))
        ->willReturn(['id' => 'ORDTST01ABC', 'status' => 'created']);

    $service = new OrderService(new NullLogger(), $mpClient, $this->createMock(OrderRepository::class));

    $result = $service->create([
        'amount' => 1500.00,
        'device_id' => 'DEVICE-001',
        'external_reference' => 'REF-001',
        'description' => 'Test order',
    ]);

    expect($result['id'])->toBe('ORDTST01ABC');
    expect($result['status'])->toBe('created');
});

it('creates order with optional ticket_number and expiration_time', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->expects($this->once())
        ->method('createOrder')
        ->with($this->callback(
            fn (array $body) =>
            $body['config']['point']['ticket_number'] === 'TK-999'
            && $body['expiration_time'] === 300
        ))
        ->willReturn(['id' => 'ORD-002', 'status' => 'created']);

    $service = new OrderService(new NullLogger(), $mpClient, $this->createMock(OrderRepository::class));

    $result = $service->create([
        'amount' => 500,
        'device_id' => 'DEV-2',
        'ticket_number' => 'TK-999',
        'expiration_time' => 300,
    ]);

    expect($result['id'])->toBe('ORD-002');
});

it('fetches order and updates local status', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')->willReturn(['id' => 'ORD-003', 'status' => 'paid', 'status_detail' => 'accredited']);

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('findByOrderId')->willReturn(['id' => 1, 'status' => 'created']);
    $orderRepo->expects($this->once())
        ->method('update')
        ->with('ORD-003', $this->callback(
            fn (array $data) =>
            $data['status'] === 'paid' && $data['status_detail'] === 'accredited'
        ));

    $service = new OrderService(new NullLogger(), $mpClient, $orderRepo);
    $result = $service->get('ORD-003');

    expect($result['status'])->toBe('paid');
});

it('fetches order without local record (no update)', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('getOrder')->willReturn(['id' => 'ORD-004', 'status' => 'created']);

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('findByOrderId')->willReturn(null);
    $orderRepo->expects($this->never())->method('update');

    $service = new OrderService(new NullLogger(), $mpClient, $orderRepo);
    $result = $service->get('ORD-004');

    expect($result['id'])->toBe('ORD-004');
});

it('cancels an order and updates local status', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('cancelOrder')->willReturn(['id' => 'ORD-005', 'status' => 'canceled']);

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->expects($this->once())
        ->method('update')
        ->with('ORD-005', $this->callback(
            fn (array $data) =>
            $data['status'] === 'canceled' && $data['action'] === 'order.canceled'
        ));

    $service = new OrderService(new NullLogger(), $mpClient, $orderRepo);
    $result = $service->cancel('ORD-005');

    expect($result['status'])->toBe('canceled');
});

it('refunds an order and updates local status', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->method('refundOrder')->willReturn(['id' => 'ORD-006', 'status' => 'refunded']);

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->expects($this->once())
        ->method('update')
        ->with('ORD-006', $this->callback(
            fn (array $data) =>
            $data['status'] === 'refunded' && $data['action'] === 'order.refunded'
        ));

    $service = new OrderService(new NullLogger(), $mpClient, $orderRepo);
    $result = $service->refund('ORD-006');

    expect($result['status'])->toBe('refunded');
});

it('refunds order with specific transaction_id', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->expects($this->once())
        ->method('refundOrder')
        ->with('ORD-007', ['transactions' => [['id' => 'TXN-001']]])
        ->willReturn(['id' => 'ORD-007', 'status' => 'refunded']);

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('update')->willReturn(true);

    $service = new OrderService(new NullLogger(), $mpClient, $orderRepo);
    $service->refund('ORD-007', 'TXN-001');
});

it('refunds order without transaction_id sends null body', function (): void {
    $mpClient = $this->createMock(MercadoPagoClient::class);
    $mpClient->expects($this->once())
        ->method('refundOrder')
        ->with('ORD-008', null)
        ->willReturn(['id' => 'ORD-008', 'status' => 'refunded']);

    $orderRepo = $this->createMock(OrderRepository::class);
    $orderRepo->method('update')->willReturn(true);

    $service = new OrderService(new NullLogger(), $mpClient, $orderRepo);
    $service->refund('ORD-008');
});
