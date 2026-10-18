<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\OrderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class OrderController
{
    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->parseBody($request);
        if ($body === null) {
            return $this->jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
        }

        $result = $this->orderService->create($body, $request->getHeaderLine('X-Idempotency-Key'));

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->orderService->get($args['id']);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->orderService->cancel($args['id'], $request->getHeaderLine('X-Idempotency-Key'));

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function refund(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $this->parseBody($request);
        if ($body === null) {
            return $this->jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
        }

        $result = $this->orderService->refund($args['id'], $body['transaction_id'] ?? null, $request->getHeaderLine('X-Idempotency-Key'));

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function parseBody(ServerRequestInterface $request): ?array
    {
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function jsonResponse(ResponseInterface $response, array $data, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
