<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\BearerAuthMiddleware;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\RequestLoggerMiddleware;
use App\Infrastructure\Database;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\IntentRepository;
use App\Infrastructure\Repositories\PaymentRepository;
use App\Infrastructure\Repositories\TerminalRepository;
use App\Infrastructure\Repositories\WebhookLogRepository;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

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

    Database::class => function () {
        return new Database();
    },

    TerminalRepository::class => \DI\autowire(TerminalRepository::class),
    IntentRepository::class => \DI\autowire(IntentRepository::class),
    PaymentRepository::class => \DI\autowire(PaymentRepository::class),
    WebhookLogRepository::class => \DI\autowire(WebhookLogRepository::class),

    MercadoPagoClient::class => \DI\autowire(MercadoPagoClient::class)
        ->constructorParameter('accessToken', $_ENV['MP_ACCESS_TOKEN']),

    // Middleware
    ErrorHandlerMiddleware::class => \DI\autowire(ErrorHandlerMiddleware::class),
    RequestLoggerMiddleware::class => \DI\autowire(RequestLoggerMiddleware::class),
    BearerAuthMiddleware::class => \DI\autowire(BearerAuthMiddleware::class),

    // Controllers
    HealthController::class => \DI\autowire(HealthController::class),
    WebhookController::class => \DI\autowire(WebhookController::class),
    IntentController::class => \DI\autowire(IntentController::class),
    PaymentController::class => \DI\autowire(PaymentController::class),
];
