<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Slim\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $response = new Response(500);
            $response->getBody()->write(json_encode([
                'error' => 'Internal Server Error',
                'message' => $_ENV['APP_DEBUG'] === 'true' ? $e->getMessage() : 'Something went wrong',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }
}
