<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\WebhookProcessor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /webhook — Mercado Pago notification endpoint.
 *
 * Delegates all processing to WebhookProcessor. Always returns 200 so MP
 * stops retrying. No bearer auth — the webhook uses HMAC-SHA256 signature
 * validation instead.
 */
class WebhookController
{
    public function __construct(
        private readonly WebhookProcessor $processor
    ) {
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->processor->process($request);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
