<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Slim\Psr7\Response;
use Slim\Exception\HttpException;
use App\Infrastructure\MercadoPago\MercadoPagoException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Catches unhandled exceptions and returns JSON error responses.
 *
 * Respects APP_DEBUG for stack traces. Logs errors with full context.
 */
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

            $status = $e instanceof HttpException ? $e->getCode() : 500;
            if ($e instanceof MercadoPagoException) {
                $status = match ($e->getHttpStatus()) {
                    400, 404, 409, 422 => $e->getHttpStatus(),
                    default => 502,
                };
            }
            $response = new Response($status);
            $response->getBody()->write(json_encode([
                'error' => $response->getReasonPhrase(),
                'message' => $e instanceof HttpException || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $e->getMessage() : 'Something went wrong',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }
}
