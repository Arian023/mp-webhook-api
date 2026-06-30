<?php

declare(strict_types=1);

use App\Application\Services\IntentService;
use App\Application\Services\IntentWebhookHandler;
use App\Application\Services\OrderService;
use App\Application\Services\OrderWebhookHandler;
use App\Application\Services\PaymentService;
use App\Application\Services\PaymentWebhookHandler;
use App\Application\Services\WebhookProcessor;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\BearerAuthMiddleware;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\RequestLoggerMiddleware;
use App\Infrastructure\Database;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\IntentRepository;
use App\Infrastructure\Repositories\OrderRepository;
use App\Infrastructure\Repositories\PaymentRepository;
use App\Infrastructure\Repositories\TerminalRepository;
use App\Infrastructure\Repositories\WebhookLogRepository;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

use function DI\autowire;

return [
    LoggerInterface::class => function () {
        $logger = new Logger('mp-webhook');
        $logger->pushHandler(
            new StreamHandler(
                __DIR__ . '/../logs/app.log',
                Level::fromName($_ENV['LOG_LEVEL'] ?? 'debug')
            )
        );
        return $logger;
    },

    Database::class => fn () => new Database(),

    TerminalRepository::class => autowire(TerminalRepository::class),
    IntentRepository::class => autowire(IntentRepository::class),
    PaymentRepository::class => autowire(PaymentRepository::class),
    OrderRepository::class => autowire(OrderRepository::class),
    WebhookLogRepository::class => autowire(WebhookLogRepository::class),

    MercadoPagoClient::class => autowire(MercadoPagoClient::class)
        ->constructorParameter('accessToken', $_ENV['MP_ACCESS_TOKEN']),

    // Application services
    WebhookProcessor::class => autowire(WebhookProcessor::class),
    IntentWebhookHandler::class => autowire(IntentWebhookHandler::class),
    PaymentWebhookHandler::class => autowire(PaymentWebhookHandler::class),
    OrderWebhookHandler::class => autowire(OrderWebhookHandler::class),
    IntentService::class => autowire(IntentService::class),
    PaymentService::class => autowire(PaymentService::class),
    OrderService::class => autowire(OrderService::class),

    // Middleware
    ErrorHandlerMiddleware::class => autowire(ErrorHandlerMiddleware::class),
    RequestLoggerMiddleware::class => autowire(RequestLoggerMiddleware::class),
    BearerAuthMiddleware::class => autowire(BearerAuthMiddleware::class),

    // Controllers
    HealthController::class => autowire(HealthController::class),
    WebhookController::class => autowire(WebhookController::class),
    IntentController::class => autowire(IntentController::class),
    PaymentController::class => autowire(PaymentController::class),
    OrderController::class => autowire(OrderController::class),
];
