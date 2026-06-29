<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\PaymentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /api/payments — payment details and refunds.
 *
 * Thin controller that delegates to PaymentService. Protected by bearer auth.
 * Endpoints: GET details, POST refund.
 */
class PaymentController
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->paymentService->get((int) $args['id']);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function refund(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->paymentService->refund((int) $args['id']);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
