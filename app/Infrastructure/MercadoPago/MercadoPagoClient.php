<?php

declare(strict_types=1);

namespace App\Infrastructure\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Psr\Log\LoggerInterface;

class MercadoPagoClient
{
    private Client $client;

    public function __construct(
        private string $accessToken,
        private LoggerInterface $logger
    ) {
        $handlerStack = HandlerStack::create();
        $handlerStack->push(GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 5,
            'retry_on_status' => [429, 500, 502, 503, 504],
            'retry_on_timeout' => true,
            'on_retry_callback' => function (int $attemptNumber, float $delay, array $requestOptions, ?array $response) {
                $status = $response ? $response->getStatusCode() : 'timeout';
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
