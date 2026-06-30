<?php

declare(strict_types=1);

namespace App\Infrastructure\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for the Mercado Pago API.
 *
 * Wraps Guzzle with retry middleware (5 attempts on 429/5xx/timeout).
 * Provides typed methods for the legacy Point Integration API, the
 * shared Payments API, and the new Orders API. All methods throw
 * MercadoPagoException on failure.
 */
class MercadoPagoClient
{
    private readonly Client $client;

    private int $requestCounter = 0;

    public function __construct(
        private readonly string $accessToken,
        private readonly LoggerInterface $logger
    ) {
        $handlerStack = HandlerStack::create();
        $handlerStack->push(GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 5,
            'retry_on_status' => [429, 500, 502, 503, 504],
            'retry_on_timeout' => true,
            'on_retry_callback' => function (int $attemptNumber, float $delay, array $requestOptions, ?ResponseInterface $response): void {
                $status = $response instanceof ResponseInterface ? $response->getStatusCode() : 'timeout';
                $this->logger->warning('MP API retry', [
                    'attempt' => $attemptNumber,
                    'delay' => round($delay, 2),
                    'status' => $status,
                    'uri' => $requestOptions['uri'] ?? 'unknown',
                ]);
            },
        ]));

        $this->client = new Client([
            'base_uri' => 'https://api.mercadopago.com',
            'timeout' => 25,
            'handler' => $handlerStack,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function createIntent(string $deviceId, array $body): array
    {
        $this->logger->debug('MP API: createIntent', ['device_id' => $deviceId]);
        return $this->request('POST', "/point/integration-api/devices/{$deviceId}/payment-intents", ['json' => $body]);
    }

    public function getIntent(string $intentId): array
    {
        $this->logger->debug('MP API: getIntent', ['intent_id' => $intentId]);
        return $this->request('GET', "/point/integration-api/payment-intents/{$intentId}");
    }

    public function cancelIntent(string $deviceId, string $intentId): array
    {
        $this->logger->debug('MP API: cancelIntent', ['device_id' => $deviceId, 'intent_id' => $intentId]);
        return $this->request('DELETE', "/point/integration-api/devices/{$deviceId}/payment-intents/{$intentId}");
    }

    public function getPayment(int $paymentId): array
    {
        $this->logger->debug('MP API: getPayment', ['payment_id' => $paymentId]);
        return $this->request('GET', "/v1/payments/{$paymentId}");
    }

    public function refundPayment(int $paymentId): array
    {
        $this->logger->debug('MP API: refundPayment', ['payment_id' => $paymentId]);
        return $this->request('POST', "/v1/payments/{$paymentId}/refunds");
    }

    public function createOrder(array $body): array
    {
        $this->requestCounter++;
        $idempotencyKey = sprintf('mp-webhook-%d-%d', time(), $this->requestCounter);
        $this->logger->debug('MP API: createOrder');
        return $this->request('POST', '/v1/orders', [
            'json' => $body,
            'headers' => ['X-Idempotency-Key' => $idempotencyKey],
        ]);
    }

    public function getOrder(string $orderId): array
    {
        $this->logger->debug('MP API: getOrder', ['order_id' => $orderId]);
        return $this->request('GET', "/v1/orders/{$orderId}");
    }

    public function cancelOrder(string $orderId): array
    {
        $this->requestCounter++;
        $idempotencyKey = sprintf('mp-webhook-cancel-%d-%d', time(), $this->requestCounter);
        $this->logger->debug('MP API: cancelOrder', ['order_id' => $orderId]);
        return $this->request('POST', "/v1/orders/{$orderId}/cancel", [
            'headers' => ['X-Idempotency-Key' => $idempotencyKey],
        ]);
    }

    public function refundOrder(string $orderId, ?array $body = null): array
    {
        $this->requestCounter++;
        $idempotencyKey = sprintf('mp-webhook-refund-%d-%d', time(), $this->requestCounter);
        $this->logger->debug('MP API: refundOrder', ['order_id' => $orderId]);
        $options = ['headers' => ['X-Idempotency-Key' => $idempotencyKey]];
        if ($body !== null) {
            $options['json'] = $body;
        }
        return $this->request('POST', "/v1/orders/{$orderId}/refund", $options);
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->client->request($method, $uri, $options);
            $body = json_decode($response->getBody()->getContents(), true);

            $this->logger->debug('MP API response', [
                'method' => $method,
                'uri' => $uri,
                'status' => $response->getStatusCode(),
            ]);

            return $body;
        } catch (GuzzleException $e) {
            $this->logger->error('MP API error', [
                'method' => $method,
                'uri' => $uri,
                'message' => $e->getMessage(),
            ]);
            throw new MercadoPagoException("MP API {$method} {$uri} failed: {$e->getMessage()}", 0, $e);
        }
    }
}
