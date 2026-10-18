<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Helpers\PaymentDataMapper;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\IntentRepository;
use App\Infrastructure\Repositories\PaymentRepository;
use Psr\Log\LoggerInterface;

/**
 * CRUD operations for payment intents via the legacy Point Integration API.
 *
 * Used by the /api/intents endpoints. Each method calls the MP API,
 * persists the result in the local DB, and returns the MP response.
 * The legacy API is: POST/GET/DELETE /point/integration-api/...
 */
class IntentService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MercadoPagoClient $mpClient,
        private readonly IntentRepository $intentRepository,
        private readonly PaymentRepository $paymentRepository,
    ) {
    }

    public function create(string $deviceId, array $body): array
    {
        $amount = $body['amount'] ?? 0;
        $externalRef = $body['additional_info']['external_reference'] ?? '';
        $ticketNumber = $body['additional_info']['ticket_number'] ?? null;

        $mpBody = [
            'amount' => (int) $amount,
            'additional_info' => [
                'external_reference' => $externalRef,
                'ticket_number' => $ticketNumber,
                'print_on_terminal' => true,
            ],
        ];

        $response = $this->mpClient->createIntent($deviceId, $mpBody);

        $this->intentRepository->create([
            'intent_id' => $response['id'],
            'device_id' => $deviceId,
            'external_ref' => $externalRef,
            'amount' => (int) $amount / 100,
            'intent_status' => 'OPEN',
            'ticket_number' => $ticketNumber,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_by' => 'api',
        ]);

        $this->logger->info('Intent created', [
            'intent_id' => $response['id'],
            'device_id' => $deviceId,
            'amount' => $amount,
        ]);

        return $response;
    }

    public function get(string $intentId): array
    {
        $intent = $this->intentRepository->findByIntentId($intentId);
        $response = $this->mpClient->getIntent($intentId);

        if (isset($response['payment']['id'])) {
            $paymentId = (int) $response['payment']['id'];
            $existingPayment = $this->paymentRepository->findByPaymentId($paymentId);

            if ($existingPayment === null) {
                try {
                    $paymentData = $this->mpClient->getPayment($paymentId);
                    $this->paymentRepository->create(array_merge(
                        PaymentDataMapper::mapPaymentData($paymentData, $intentId),
                        ['updated_by' => 'api']
                    ));
                } catch (\Throwable $e) {
                    $this->logger->warning('Could not fetch payment details', [
                        'payment_id' => $paymentId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($intent !== null) {
            $this->intentRepository->updateStatus($intentId, $response['state'] ?? 'UNKNOWN');
        }

        return $response;
    }

    public function cancel(string $deviceId, string $intentId): array
    {
        $response = $this->mpClient->cancelIntent($deviceId, $intentId);

        $this->intentRepository->updateStatus($intentId, 'CANCELED');

        $this->logger->info('Intent canceled', [
            'intent_id' => $intentId,
            'device_id' => $deviceId,
        ]);

        return $response;
    }

    public function refund(string $intentId, ?string $reason, string $idempotencyKey): array
    {
        $intent = $this->intentRepository->findByIntentId($intentId);

        if ($intent === null || $intent['payment_id'] === null) {
            throw new \RuntimeException("Intent {$intentId} has no associated payment");
        }

        $paymentId = (int) $intent['payment_id'];
        $response = $this->mpClient->refundPayment($paymentId, $idempotencyKey);

        $paymentData = $this->mpClient->getPayment($paymentId);
        $this->paymentRepository->update($paymentId, array_merge(
            PaymentDataMapper::mapPaymentUpdate($paymentData),
            ['updated_by' => 'api'],
        ));

        $this->logger->info('Payment refunded', [
            'intent_id' => $intentId,
            'payment_id' => $paymentId,
            'reason' => $reason,
        ]);

        return $response;
    }
}
