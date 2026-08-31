<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Bearer token authentication for /api/* routes.
 *
 * Compares the Authorization header against MP_ACCESS_TOKEN env var.
 * The /webhook route is excluded — it uses HMAC signature validation instead.
 */
class BearerAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '' || !str_starts_with($authHeader, 'Bearer ')) {
            $response = new Response(401);
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $token = mb_substr($authHeader, 7);
        $expectedToken = $_ENV['MP_ACCESS_TOKEN'] ?? '';

        if (!hash_equals($expectedToken, $token)) {
            $response = new Response(401);
            $response->getBody()->write(json_encode(['error' => 'Invalid token']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
