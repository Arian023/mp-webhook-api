<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /health — liveness check.
 *
 * Tests the DB connection and returns status JSON. Used by load balancers
 * and monitoring to verify the app is running.
 */
class HealthController
{
    public function __construct(
        private readonly Database $database,
        private readonly LoggerInterface $logger
    ) {
    }

    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = ['status' => 'ok', 'timestamp' => date('c')];

        try {
            $db = $this->database->getConnection();
            $db->query('SELECT 1');
            $status['database'] = 'connected';
        } catch (\Exception $e) {
            $this->logger->error('Health check failed: ' . $e->getMessage());
            $status['status'] = 'error';
            $status['database'] = 'disconnected';
            $response = $response->withStatus(500);
        }

        $response->getBody()->write(json_encode($status));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
