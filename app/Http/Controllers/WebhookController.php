<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class WebhookController
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->logger->info('Webhook received');

        $response->getBody()->write(json_encode([
            'message' => 'Webhook received',
            'status' => 'pending_implementation',
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
