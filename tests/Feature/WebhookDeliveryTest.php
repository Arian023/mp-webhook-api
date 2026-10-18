<?php

declare(strict_types=1);

use App\Application\Services\IntentWebhookHandler;
use App\Application\Services\OrderWebhookHandler;
use App\Application\Services\PaymentWebhookHandler;
use App\Application\Services\WebhookProcessor;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Infrastructure\Database;
use App\Infrastructure\Repositories\WebhookLogRepository;
use Psr\Log\NullLogger;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

it('returns 503 and rolls back when processing fails, then acknowledges a successful retry', function (): void {
    $previous = $_ENV;
    unset($_ENV['MP_WEBHOOK_SECRET']);
    $_ENV['SKIP_WEBHOOK_VALIDATION'] = 'true';
    try {
        $handler = $this->createMock(OrderWebhookHandler::class);
        $attempt = 0;
        $handler->expects($this->exactly(2))->method('handle')->willReturnCallback(static function () use (&$attempt): void {
            if ($attempt++ === 0) {
                throw new RuntimeException('Provider unavailable');
            }
        });
        $database = $this->createMock(Database::class);
        $database->expects($this->exactly(2))->method('beginTransaction');
        $database->method('inTransaction')->willReturn(true);
        $database->expects($this->once())->method('rollback');
        $database->expects($this->once())->method('commit');
        $log = $this->createMock(WebhookLogRepository::class);
        $log->expects($this->exactly(2))->method('log');
        $processor = new WebhookProcessor(new NullLogger(), $log, $this->createMock(IntentWebhookHandler::class), $this->createMock(PaymentWebhookHandler::class), $handler, $database);
        $app = AppFactory::create();
        $app->post('/webhook', [new WebhookController($processor), 'handle']);
        $app->add(new ErrorHandlerMiddleware(new NullLogger()));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/webhook?type=orders&data.id=ORD-123')
            ->withBody((new StreamFactory())->createStream('{"action":"order.updated","data":{"id":"ORD-123"}}'));

        expect($app->handle($request)->getStatusCode())->toBe(503);
        expect($app->handle($request)->getStatusCode())->toBe(200);
    } finally {
        $_ENV = $previous;
    }
});
