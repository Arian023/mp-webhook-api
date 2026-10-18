<?php

declare(strict_types=1);

use App\Http\Middleware\ErrorHandlerMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;

it('preserves the status of an HTTP exception', function (): void {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing');
    $handler = $this->createMock(RequestHandlerInterface::class);
    $handler->method('handle')->willThrowException(new HttpNotFoundException($request));

    $response = (new ErrorHandlerMiddleware(new NullLogger()))->process($request, $handler);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json');
});
