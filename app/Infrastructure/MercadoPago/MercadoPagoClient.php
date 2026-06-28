<?php

declare(strict_types=1);

namespace App\Infrastructure\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;

class MercadoPagoClient
{
    private Client $client;

    public function __construct(private string $accessToken)
    {
        $handlerStack = HandlerStack::create();
        $handlerStack->push(GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 5,
            'retry_on_status' => [429, 500, 502, 503, 504],
            'retry_on_timeout' => true,
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
        $response = $this->client->post("/point/integration-api/devices/{$deviceId}/payment-intents", [
            'json' => $body,
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }

    public function getIntent(string $intentId): array
    {
        $response = $this->client->get("/point/integration-api/payment-intents/{$intentId}");
        return json_decode($response->getBody()->getContents(), true);
    }

    public function cancelIntent(string $deviceId, string $intentId): array
    {
        $response = $this->client->delete("/point/integration-api/devices/{$deviceId}/payment-intents/{$intentId}");
        return json_decode($response->getBody()->getContents(), true);
    }

    public function getPayment(int $paymentId): array
    {
        $response = $this->client->get("/v1/payments/{$paymentId}");
        return json_decode($response->getBody()->getContents(), true);
    }

    public function refundPayment(int $paymentId): array
    {
        $response = $this->client->post("/v1/payments/{$paymentId}/refunds");
        return json_decode($response->getBody()->getContents(), true);
    }
}
