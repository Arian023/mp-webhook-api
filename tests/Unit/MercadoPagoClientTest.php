<?php

declare(strict_types=1);

use App\Infrastructure\MercadoPago\MercadoPagoClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

it('logs retries without a callback type error and returns the recovered response', function (): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning')->with(
        'MP API retry',
        $this->callback(fn (array $context): bool => $context['attempt'] === 1
            && $context['status'] === 503
            && $context['uri'] === 'https://api.mercadopago.com/v1/payments/123'),
    );
    $stack = HandlerStack::create(new MockHandler([
        new Response(503, ['Retry-After' => '0']),
        new Response(200, [], '{"id":123}'),
    ]));
    $client = new MercadoPagoClient('test-token', $logger, $stack);

    expect($client->getPayment(123))->toBe(['id' => 123]);
});

it('preserves the caller idempotency key across retries and repeated calls', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(503, ['Retry-After' => '0']),
        new Response(200, [], '{"id":"refund-1"}'),
        new Response(200, [], '{"id":"refund-1"}'),
    ]));
    $client = new MercadoPagoClient('test-token', new \Psr\Log\NullLogger(), $stack);
    $stack->push(\GuzzleHttp\Middleware::history($history));
    $client->refundPayment(123, 'business-refund-123');
    $client->refundPayment(123, 'business-refund-123');

    expect($history)->toHaveCount(3);
    foreach ($history as $transfer) {
        expect($transfer['request']->getHeaderLine('X-Idempotency-Key'))->toBe('business-refund-123');
    }
});

it('does not retry unsafe legacy mutations', function (): void {
    $mock = new MockHandler([new Response(503), new Response(200, [], '{"id":"duplicate"}')]);
    $client = new MercadoPagoClient('test-token', new \Psr\Log\NullLogger(), HandlerStack::create($mock));

    expect(fn () => $client->createIntent('DEVICE-1', ['amount' => 100]))
        ->toThrow(\App\Infrastructure\MercadoPago\MercadoPagoException::class);
    expect($mock->count())->toBe(1);
});

it('retains provider status codes for client errors', function (): void {
    $client = new MercadoPagoClient('test-token', new \Psr\Log\NullLogger(), HandlerStack::create(new MockHandler([
        new Response(404, [], '{"message":"not found"}'),
    ])));
    try {
        $client->getPayment(123);
        $this->fail('Expected provider error');
    } catch (\App\Infrastructure\MercadoPago\MercadoPagoException $e) {
        expect($e->getHttpStatus())->toBe(404);
    }
});

it('rejects invalid idempotency keys before sending requests', function (): void {
    $mock = new MockHandler([]);
    $client = new MercadoPagoClient('test-token', new \Psr\Log\NullLogger(), HandlerStack::create($mock));
    expect(fn () => $client->refundPayment(123, ''))->toThrow(InvalidArgumentException::class);
});
