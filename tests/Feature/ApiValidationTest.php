<?php

declare(strict_types=1);

use App\Http\Middleware\ApiValidationMiddleware;
use App\Http\Middleware\BearerAuthMiddleware;
use App\Http\Middleware\ErrorHandlerMiddleware;
use Psr\Log\NullLogger;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Routing\RouteCollectorProxy;

function validationApp(): \Slim\App
{
    $app = AppFactory::create();
    $app->group('/api', function (RouteCollectorProxy $group): void {
        $ok = static fn ($request, $response) => $response->withStatus(204);
        $group->post('/orders', $ok);
        $group->post('/orders/{id}/refund', $ok);
        $group->post('/intents', $ok);
        $group->get('/payments/{id}', $ok);
    })->add(new ApiValidationMiddleware())->add(new BearerAuthMiddleware());
    $app->add(new ErrorHandlerMiddleware(new NullLogger()));
    return $app;
}

it('rejects malformed financial requests before reaching the service', function (string $method, string $path, string $body, string $contentType, string $key, int $status): void {
    $previous = $_ENV;
    $_ENV['API_AUTH_TOKEN'] = 'local-token';
    try {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer local-token')
            ->withHeader('Content-Type', $contentType)
            ->withHeader('X-Idempotency-Key', $key)
            ->withBody((new StreamFactory())->createStream($body));
        $response = validationApp()->handle($request);
        expect($response->getStatusCode())->toBe($status);
        if ($status >= 400) {
            expect($response->getHeaderLine('Content-Type'))->toBe('application/json');
        }
    } finally {
        $_ENV = $previous;
    }
})->with([
    ['POST', '/api/orders', '{}', 'application/json', '', 400],
    ['POST', '/api/orders', '[]', 'application/json', 'op-1', 400],
    ['POST', '/api/orders', '{broken', 'application/json', 'op-1', 400],
    ['POST', '/api/orders', '{}', 'text/plain', 'op-1', 415],
    ['POST', '/api/orders', '{"amount":-1,"device_id":"DEV"}', 'application/json', 'op-1', 400],
    ['POST', '/api/orders', '{"amount":true,"device_id":"DEV"}', 'application/json', 'op-1', 400],
    ['POST', '/api/orders', '{"amount":10,"device_id":[]}', 'application/json', 'op-1', 400],
    ['POST', '/api/orders', '{"amount":10,"device_id":"DEV","external_reference":"REF","expiration_time":300}', 'application/json', 'op-1', 400],
    ['POST', '/api/orders', '{"amount":"10.25","device_id":"DEV","external_reference":"REF","expiration_time":"PT5M"}', 'application/json', 'op-1', 204],
    ['POST', '/api/orders/ORD-1/refund', '{"transaction_id":[]}', 'application/json', 'op-1', 400],
    ['GET', '/api/payments/abc', '', '', '', 400],
    ['GET', '/api/payments/0', '', '', '', 400],
    ['GET', '/api/payments/9999999999999999999999', '', '', '', 400],
    ['GET', '/api/payments/123', '', '', '', 204],
]);

it('does not accept the provider credential as the local API credential', function (): void {
    $previous = $_ENV;
    $_ENV['MP_ACCESS_TOKEN'] = 'provider-token';
    unset($_ENV['API_AUTH_TOKEN']);
    try {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/payments/123')
            ->withHeader('Authorization', 'Bearer provider-token');
        expect(validationApp()->handle($request)->getStatusCode())->toBe(401);
    } finally {
        $_ENV = $previous;
    }
});
