<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class IntentController
{
    public function __construct(private LoggerInterface $logger) {}

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode(['status' => 'pending_implementation']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $response->getBody()->write(json_encode(['status' => 'pending_implementation']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $response->getBody()->write(json_encode(['status' => 'pending_implementation']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function refund(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $response->getBody()->write(json_encode(['status' => 'pending_implementation']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
