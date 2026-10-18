<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\IntentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /api/intents — payment intent CRUD.
 *
 * Thin controller that delegates to IntentService. Protected by bearer auth.
 * Endpoints: POST create, GET status, DELETE cancel, POST refund.
 */
class IntentController
{
    public function __construct(
        private readonly IntentService $intentService
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $deviceId = $request->getQueryParams()['device_id'] ?? '';
        $body = $this->parseBody($request);
        if ($body === null) {
            return $this->jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
        }

        $result = $this->intentService->create($deviceId, $body);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->intentService->get($args['id']);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $deviceId = $request->getQueryParams()['device_id'] ?? '';
        $result = $this->intentService->cancel($deviceId, $args['id']);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function refund(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $this->parseBody($request);
        if ($body === null) {
            return $this->jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
        }

        $result = $this->intentService->refund($args['id'], $body['reason'] ?? null, $request->getHeaderLine('X-Idempotency-Key'));

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
