<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Routing\RouteContext;

/** Reject invalid input before invoking any financial service. */
class ApiValidationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $id = RouteContext::fromRequest($request)->getRoute()?->getArgument('id');
        if ($id !== null) {
            $this->require($request, preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id) === 1, 'Invalid resource identifier');
            if (str_starts_with($path, '/api/payments/')) {
                $this->require($request, filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 'Invalid payment identifier');
            }
        }

        if ($request->getMethod() === 'POST' && (str_starts_with($path, '/api/orders') || str_ends_with($path, '/refund'))) {
            $this->require($request, preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $request->getHeaderLine('X-Idempotency-Key')) === 1, 'X-Idempotency-Key is required (1-64 letters, digits, _ or -)');
        }

        $raw = (string) $request->getBody();
        $body = [];
        if ($raw !== '') {
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new HttpException($request, 'Expected application/json', 415);
            }
            $decoded = json_decode($raw);
            $this->require($request, $decoded instanceof \stdClass, 'Expected a JSON object');
            $body = json_decode($raw, true);
        }

        foreach (['reason', 'transaction_id', 'description', 'external_reference', 'device_id', 'ticket_number'] as $field) {
            if (isset($body[$field])) {
                $this->require($request, is_string($body[$field]) && strlen($body[$field]) <= 255, "Invalid {$field}");
            }
        }
        if ($request->getMethod() === 'POST' && in_array($path, ['/api/orders', '/api/intents'], true)) {
            $amount = $body['amount'] ?? null;
            $validAmount = (is_int($amount) || is_float($amount) || is_string($amount))
                && preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', (string) $amount) === 1
                && (float) $amount > 0 && (float) $amount <= 9999999999.99;
            $this->require($request, $validAmount, 'Invalid amount');
            if ($path === '/api/intents') {
                $this->require($request, filter_var($amount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 'Intent amount must be integer centavos');
                $deviceId = $request->getQueryParams()['device_id'] ?? null;
                $this->require($request, is_string($deviceId) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $deviceId) === 1, 'Invalid device_id');
                $info = $body['additional_info'] ?? [];
                $this->require($request, is_array($info), 'Invalid additional_info');
                foreach (['external_reference', 'ticket_number'] as $field) {
                    $this->require($request, !isset($info[$field]) || (is_string($info[$field]) && strlen($info[$field]) <= 255), "Invalid additional_info.{$field}");
                }
            } else {
                $deviceId = $body['device_id'] ?? null;
                $this->require($request, is_string($deviceId) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $deviceId) === 1, 'Invalid device_id');
                $this->require($request, preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $body['external_reference'] ?? '') === 1, 'Invalid external_reference');
                $this->require($request, !isset($body['description']) || mb_strlen($body['description']) <= 150, 'Invalid description');
                $this->require($request, in_array($body['print_on_terminal'] ?? 'no_ticket', ['no_ticket', 'seller_ticket'], true), 'Invalid print_on_terminal');
                if (isset($body['expiration_time'])) {
                    $duration = $body['expiration_time'];
                    $this->require($request, is_string($duration) && preg_match('/^PT(?:(\d{1,2})H)?(?:(\d{1,3})M)?(?:(\d{1,5})S)?$/D', $duration, $parts) === 1, 'Invalid expiration_time');
                    $seconds = (int) ($parts[1] ?? 0) * 3600 + (int) ($parts[2] ?? 0) * 60 + (int) ($parts[3] ?? 0);
                    $this->require($request, $seconds >= 30 && $seconds <= 10800, 'expiration_time must be between PT30S and PT3H');
                }
            }
        }
        if ($request->getMethod() === 'DELETE') {
            $deviceId = $request->getQueryParams()['device_id'] ?? null;
            $this->require($request, is_string($deviceId) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $deviceId) === 1, 'Invalid device_id');
        }

        return $handler->handle($request);
    }

    private function require(ServerRequestInterface $request, bool $valid, string $message): void
    {
        if (!$valid) {
            throw new HttpBadRequestException($request, $message);
        }
    }
}
