<?php

declare(strict_types=1);

use App\Http\Middleware\BearerAuthMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

it('rejects missing configuration and invalid bearer credentials', function (string $expected, string $provided): void {
    $previous = $_ENV['API_AUTH_TOKEN'] ?? null;
    $_ENV['API_AUTH_TOKEN'] = $expected;

    try {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/payments/1')
            ->withHeader('Authorization', 'Bearer ' . $provided);

        expect((new BearerAuthMiddleware())->process($request, $handler)->getStatusCode())->toBe(401);
    } finally {
        if ($previous === null) {
            unset($_ENV['API_AUTH_TOKEN']);
        } else {
            $_ENV['API_AUTH_TOKEN'] = $previous;
        }
    }
})->with([['', ''], ['', 'token'], ['token', ''], ['token', 'wrong']]);

it('accepts a configured bearer token', function (): void {
    $previous = $_ENV['API_AUTH_TOKEN'] ?? null;
    $_ENV['API_AUTH_TOKEN'] = 'test-token';

    try {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new Response(204));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/payments/1')
            ->withHeader('Authorization', 'Bearer test-token');

        expect((new BearerAuthMiddleware())->process($request, $handler)->getStatusCode())->toBe(204);
    } finally {
        if ($previous === null) {
            unset($_ENV['API_AUTH_TOKEN']);
        } else {
            $_ENV['API_AUTH_TOKEN'] = $previous;
        }
    }
});
