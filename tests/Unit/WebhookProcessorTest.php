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
    ?\App\Infrastructure\Database $database = null,
): WebhookProcessor {
    return new WebhookProcessor(
        new NullLogger(),
        $logRepo,
        $intentHandler,
        $paymentHandler,
        $orderHandler,
        $database ?? test()->createMock(\App\Infrastructure\Database::class),
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

it('ignores scalar JSON bodies without throwing', function (string $json): void {
    $logRepo = $this->createMock(WebhookLogRepository::class);
    $logRepo->expects($this->once())->method('log');
    $intentHandler = $this->createMock(IntentWebhookHandler::class);
    $intentHandler->expects($this->never())->method('handle');
    $processor = buildProcessor(
        $logRepo,
        $intentHandler,
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
    );
    $request = webhookRequest('point_integration_wh', '123', []);
    $request = $request->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream($json));

    expect($processor->process($request)['message'])->toBe('Webhook processed');
})->with(['true', '123', '"text"', 'null', '{invalid']);

it('rejects malformed signature headers without warnings', function (string $signature): void {
    $_ENV['MP_WEBHOOK_SECRET'] = 'test-secret';
    try {
        $logRepo = $this->createMock(WebhookLogRepository::class);
        $logRepo->expects($this->once())->method('log')->with(
            $this->callback(fn (array $data): bool => $data['signature_valid'] === 0),
        );
        $intentHandler = $this->createMock(IntentWebhookHandler::class);
        $intentHandler->expects($this->never())->method('handle');
        $processor = buildProcessor(
            $logRepo,
            $intentHandler,
            $this->createMock(PaymentWebhookHandler::class),
            $this->createMock(OrderWebhookHandler::class),
        );
        $request = webhookRequest('point_integration_wh', '123', [
            'id' => '123', 'amount' => 100, 'state' => 'OPEN',
        ], ['X-Signature' => $signature, 'X-Request-Id' => 'req-test']);

        expect($processor->process($request)['message'])->toBe('Webhook processed');
    } finally {
        unset($_ENV['MP_WEBHOOK_SECRET']);
    }
})->with(['invalid', 'ts=123,v1', 'ts=123,ts=456,v1=abc', 'ts=abc,v1=abc']);

it('dispatches intent webhook to IntentWebhookHandler', function (): void {
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';

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

    unset($_ENV['SKIP_WEBHOOK_VALIDATION']);
});

it('dispatches payment webhook to PaymentWebhookHandler', function (): void {
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';

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

    unset($_ENV['SKIP_WEBHOOK_VALIDATION']);
});

it('dispatches order webhook to OrderWebhookHandler', function (): void {
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';

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

    unset($_ENV['SKIP_WEBHOOK_VALIDATION']);
});

it('rejects invalid intent body (missing id)', function (): void {
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';

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

    unset($_ENV['SKIP_WEBHOOK_VALIDATION']);
});

it('rejects invalid payment body (missing date_created)', function (): void {
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';

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

    unset($_ENV['SKIP_WEBHOOK_VALIDATION']);
});

it('rejects invalid order body (missing action)', function (): void {
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';

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

    unset($_ENV['SKIP_WEBHOOK_VALIDATION']);
});

it('logs unknown webhook type without error', function (): void {
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';

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

    unset($_ENV['SKIP_WEBHOOK_VALIDATION']);
});

it('logs webhook even when handler throws exception', function (): void {
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';

    $intentHandler = $this->createMock(IntentWebhookHandler::class);
    $intentHandler->method('handle')->willThrowException(new RuntimeException('DB error'));

    $logRepo = $this->createMock(WebhookLogRepository::class);
    $logRepo->expects($this->once())->method('log')->with($this->callback(fn (array $data): bool => $data['response_code'] === 503));
    $database = $this->createMock(\App\Infrastructure\Database::class);
    $database->expects($this->once())->method('beginTransaction');
    $database->method('inTransaction')->willReturn(true);
    $database->expects($this->once())->method('rollback');
    $database->expects($this->never())->method('commit');
    $processor = buildProcessor(
        $logRepo,
        $intentHandler,
        $this->createMock(PaymentWebhookHandler::class),
        $this->createMock(OrderWebhookHandler::class),
        $database,
    );
    $request = webhookRequest('point_integration_wh', 'intent-fail', [
        'id' => 'intent-fail',
        'amount' => 100000,
        'state' => 'OPEN',
    ]);

    try {
        expect(fn () => $processor->process($request))->toThrow(\Slim\Exception\HttpException::class, 'Webhook processing failed');
    } finally {
        unset($_ENV['SKIP_WEBHOOK_VALIDATION']);
    }
});

it('validates HMAC signature when MP_WEBHOOK_SECRET is set', function (): void {
    $secret = 'test-webhook-secret';
    $ts = (string) time();
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
    $ts = (string) time();
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
        ->withHeader('X-Signature', "ts={$ts},v1=invalidsignature")
        ->withHeader('X-Request-Id', 'req-bad')
        ->withBody($bodyStream);

    $result = $processor->process($request);

    expect($result['message'])->toBe('Webhook processed');

    unset($_ENV['MP_WEBHOOK_SECRET']);
});

it('dispatches signed current Point and QR events with millisecond timestamps', function (string $type, bool $requestIdPresent): void {
    $_ENV['MP_WEBHOOK_SECRET'] = 'test-secret';
    try {
        $id = 'ORD01JYHTJA9M4NKTA06K7M808NJD';
        $ts = (string) (time() * 1000);
        $manifest = 'id:' . strtolower($id) . ';'
            . ($requestIdPresent ? 'request-id:req-test;' : '') . "ts:{$ts};";
        $signature = hash_hmac('sha256', $manifest, 'test-secret');
        $body = ['type' => $type, 'action' => 'order.processed', 'data' => ['id' => $id]];
        $handler = $this->createMock(OrderWebhookHandler::class);
        $handler->expects($this->once())->method('handle')->with($body, $id);
        $headers = ['X-Signature' => "ts= {$ts}, v1= {$signature}"];
        if ($requestIdPresent) {
            $headers['X-Request-Id'] = 'req-test';
        }
        $processor = buildProcessor(
            $this->createMock(WebhookLogRepository::class),
            $this->createMock(IntentWebhookHandler::class),
            $this->createMock(PaymentWebhookHandler::class),
            $handler,
        );

        expect($processor->process(webhookRequest($type, $id, $body, $headers))['type'])->toBe($type);
    } finally {
        unset($_ENV['MP_WEBHOOK_SECRET']);
    }
})->with(['order', 'orders'])->with([true, false]);
