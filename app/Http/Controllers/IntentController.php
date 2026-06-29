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
        $body = json_decode((string) $request->getBody(), true) ?? [];

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
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $result = $this->intentService->refund($args['id'], $body['reason'] ?? null);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
