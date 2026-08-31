<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs method, path, IP, and response time for every request.
 */
class RequestLoggerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $method = $request->getMethod();
        $uri = $request->getUri()->getPath();

        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        $ip = $forwardedFor !== '' ? trim(explode(',', $forwardedFor)[0]) : ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        $this->logger->info("Request: {$method} {$uri}", [
            'ip' => $ip,
            'query' => $request->getQueryParams(),
        ]);

        $response = $handler->handle($request);

        $duration = round((microtime(true) - $start) * 1000, 2);
        $this->logger->info("Response: {$response->getStatusCode()} ({$duration}ms)");

        return $response;
    }
}
