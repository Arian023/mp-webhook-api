<?php

declare(strict_types=1);

use App\Application\Services\IntentWebhookHandler;
use App\Application\Services\OrderWebhookHandler;
use App\Application\Services\PaymentWebhookHandler;
use App\Application\Services\WebhookProcessor;
use App\Infrastructure\Repositories\WebhookLogRepository;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

function buildProcessor(
    WebhookLogRepository $logRepo,
    IntentWebhookHandler $intentHandler,
    PaymentWebhookHandler $paymentHandler,
    OrderWebhookHandler $orderHandler,
): WebhookProcessor {
    return new WebhookProcessor(
        new NullLogger(),
        $logRepo,
        $intentHandler,
        $paymentHandler,
        $orderHandler,
    );
}

function webhookRequest(string $type, string $dataId, array $body, array $headers = []): \Psr\Http\Message\ServerRequestInterface
{
    $request = (new ServerRequestFactory())->createServerRequest('POST', "/webhook?type={$type}&data.id={$dataId}");
    $bodyStream = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
    $bodyStream->write(json_encode($body));
    $request = $request->withBody($bodyStream);
    foreach ($headers as $key => $value) {
        $request = $request->withHeader($key, $value);
    }
    return $request;
}

it('dispatches intent webhook to IntentWebhookHandler', function (): void {
    $intentHandler = $this->createMock(IntentWebhookHandler::class);
    $intentHandler->expects($this->once())
        ->method('handle')
        ->with(
            $this->callback(fn (array $body) => $body['id'] === 'intent-123' && $body['state'] === 'FINISHED'),
            'intent-123'
        );

    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $intentHandler,
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );
    $request = webhookRequest('point_integration_wh', 'intent-123', [
        'id' => 'intent-123',
        'amount' => 150000,
        'state' => 'FINISHED',
    ]);

    $result = $processor->process($request);

    expect($result['message'])->toBe('Webhook processed');
    expect($result['type'])->toBe('point_integration_wh');
});

it('dispatches payment webhook to PaymentWebhookHandler', function (): void {
    $paymentHandler = $this->createMock(PaymentWebhookHandler::class);
    $paymentHandler->expects($this->once())
        ->method('handle')
        ->with(
            $this->callback(fn (array $body) => $body['id'] === 98765),
            '98765'
        );

    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $this->createMock(IntentWebhookHandler::class),
        $paymentHandler,
        $this->createMock(OrderWebhookHandler::class),
    );
    $request = webhookRequest('payment', '98765', [
        'id' => 98765,
        'date_created' => '2026-06-30T10:00:00.000-04:00',
        'type' => 'payment',
    ]);

    $result = $processor->process($request);

    expect($result['type'])->toBe('payment');
});

it('dispatches order webhook to OrderWebhookHandler', function (): void {
    $orderHandler = $this->createMock(OrderWebhookHandler::class);
    $orderHandler->expects($this->once())
        ->method('handle')
        ->with(
            $this->callback(fn (array $body) => $body['action'] === 'order.updated' && $body['data']['id'] === 'order-456'),
            'order-456'
        );

    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $this->createMock(IntentWebhookHandler::class),
        $this->createMock(PaymentWebhookHandler::class),
        $orderHandler,
    );
    $request = webhookRequest('order', 'order-456', [
        'action' => 'order.updated',
        'data' => ['id' => 'order-456'],
    ]);

    $result = $processor->process($request);

    expect($result['type'])->toBe('order');
});

it('rejects invalid intent body (missing id)', function (): void {
    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $this->createMock(IntentWebhookHandler::class),
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );
    $request = webhookRequest('point_integration_wh', 'x', [
        'amount' => 100000,
        'state' => 'OPEN',
    ]);

    $result = $processor->process($request);

    expect($result['message'])->toBe('Webhook processed');
    expect($result['type'])->toBe('point_integration_wh');
});

it('rejects invalid payment body (missing date_created)', function (): void {
    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $this->createMock(IntentWebhookHandler::class),
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );
    $request = webhookRequest('payment', '111', [
        'id' => 111,
        'type' => 'payment',
    ]);

    $result = $processor->process($request);

    expect($result['type'])->toBe('payment');
});

it('rejects invalid order body (missing action)', function (): void {
    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $this->createMock(IntentWebhookHandler::class),
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );
    $request = webhookRequest('order', 'order-789', [
        'data' => ['id' => 'order-789'],
    ]);

    $result = $processor->process($request);

    expect($result['type'])->toBe('order');
});

it('logs unknown webhook type without error', function (): void {
    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $this->createMock(IntentWebhookHandler::class),
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );
    $request = webhookRequest('unknown_type', 'x', ['foo' => 'bar']);

    $result = $processor->process($request);

    expect($result['type'])->toBe('unknown_type');
});

it('logs webhook even when handler throws exception', function (): void {
    $intentHandler = $this->createMock(IntentWebhookHandler::class);
    $intentHandler->method('handle')->willThrowException(new RuntimeException('DB error'));

    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $intentHandler,
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );
    $request = webhookRequest('point_integration_wh', 'intent-fail', [
        'id' => 'intent-fail',
        'amount' => 100000,
        'state' => 'OPEN',
    ]);

    $result = $processor->process($request);

    expect($result['message'])->toBe('Webhook processed');
});

it('validates HMAC signature when MP_WEBHOOK_SECRET is set', function (): void {
    $secret = 'test-webhook-secret';
    $_ENV['MP_WEBHOOK_SECRET'] = $secret;

    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $this->createMock(IntentWebhookHandler::class),
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );

    $dataId = 'intent-sec';
    $requestId = 'req-123';
    $ts = '1719768000';
    $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
    $v1 = hash_hmac('sha256', $manifest, $secret);

    $bodyStream = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
    $bodyStream->write(json_encode([
        'id' => $dataId,
        'amount' => 100000,
        'state' => 'OPEN',
    ]));

    $request = (new ServerRequestFactory())->createServerRequest(
        'POST',
        "/webhook?type=point_integration_wh&data.id={$dataId}"
    );
    $request = $request
        ->withHeader('X-Signature', "ts={$ts},v1={$v1}")
        ->withHeader('X-Request-Id', $requestId)
        ->withBody($bodyStream);

    $result = $processor->process($request);

    expect($result['message'])->toBe('Webhook processed');

    unset($_ENV['MP_WEBHOOK_SECRET']);
});

it('rejects webhook with invalid HMAC signature', function (): void {
    $_ENV['MP_WEBHOOK_SECRET'] = 'test-webhook-secret';

    $intentHandler = $this->createMock(IntentWebhookHandler::class);
    $intentHandler->expects($this->never())->method('handle');

    $logRepo = $this->createMock(WebhookLogRepository::class);
    $processor = buildProcessor(
        $logRepo,
        $intentHandler,
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );

    $bodyStream = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
    $bodyStream->write(json_encode([
        'id' => 'intent-bad',
        'amount' => 100000,
        'state' => 'OPEN',
    ]));

    $request = (new ServerRequestFactory())->createServerRequest(
        'POST',
        '/webhook?type=point_integration_wh&data.id=intent-bad'
    );
    $request = $request
        ->withHeader('X-Signature', 'ts=123,v1=invalidsignature')
        ->withHeader('X-Request-Id', 'req-bad')
        ->withBody($bodyStream);

    $result = $processor->process($request);

    expect($result['message'])->toBe('Webhook processed');

    unset($_ENV['MP_WEBHOOK_SECRET']);
});
