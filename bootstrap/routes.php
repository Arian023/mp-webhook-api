<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\BearerAuthMiddleware;
use Slim\Routing\RouteCollectorProxy;

// Webhook (no auth — uses signature validation)
$app->post('/webhook', [WebhookController::class, 'handle']);

// API endpoints (bearer auth)
$app->group('/api', function (RouteCollectorProxy $group) {
    $group->post('/intents', [IntentController::class, 'create']);
    $group->get('/intents/{id}', [IntentController::class, 'get']);
    $group->delete('/intents/{id}/cancel', [IntentController::class, 'cancel']);
    $group->post('/intents/{id}/refund', [IntentController::class, 'refund']);
    $group->get('/payments/{id}', [PaymentController::class, 'get']);
    $group->post('/payments/{id}/refund', [PaymentController::class, 'refund']);
})->add(BearerAuthMiddleware::class);

// Health (no auth)
$app->get('/health', [HealthController::class, 'check']);
