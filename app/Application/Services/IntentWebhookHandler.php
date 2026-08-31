<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Helpers\DateHelper;
use App\Infrastructure\Helpers\PaymentDataMapper;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\IntentRepository;
use App\Infrastructure\Repositories\PaymentRepository;
use App\Infrastructure\Repositories\TerminalRepository;
use Psr\Log\LoggerInterface;

/**
 * Handles point_integration_wh webhooks from Point terminals.
 *
 * This is the primary webhook type. When a terminal creates, completes, or
 * cancels a payment intent, MP sends this notification. The handler:
 *  1. Finds or creates the intent record (centavos → ARS conversion)
 *  2. If payment.id is present, fetches full payment details from the MP API
 *  3. Inserts payment + charges into the DB
 *  4. Links the intent to the payment
 */
class IntentWebhookHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MercadoPagoClient $mpClient,
        private readonly IntentRepository $intentRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly TerminalRepository $terminalRepository,
    ) {
    }

    public function handle(array $body, string $dataId): void
    {
        $startTime = microtime(true);
        $state = $body['state'] ?? 'UNKNOWN';

        $this->logger->info('Handling intent webhook', [
            'intent_id' => $dataId,
            'state' => $state,
        ]);

        $intent = $this->intentRepository->findByIntentId($dataId);

        if ($intent === null) {
            $this->createIntentFromWebhook($body, $dataId);
        }

        if (isset($body['payment']['id'])) {
            $this->processPayment($body, $dataId, (int) $body['payment']['id']);
        } else {
            $this->intentRepository->updateStatus($dataId, $state);
            $this->logger->info('Intent status updated (no payment)', [
                'intent_id' => $dataId,
                'state' => $state,
            ]);
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->logger->info('Intent webhook handled', [
            'intent_id' => $dataId,
            'elapsed' => $elapsed . ' seg',
        ]);
    }

    private function createIntentFromWebhook(array $body, string $dataId): void
    {
        $state = $body['state'] ?? 'UNKNOWN';
        $deviceId = $body['device_id'] ?? '';

        if ($state === 'FINISHED' && $deviceId === '') {
            try {
                $intentData = $this->mpClient->getIntent($dataId);
                $deviceId = $intentData['device_id'] ?? '';
            } catch (\Throwable $e) {
                $this->logger->warning('Could not fetch intent from MP API', [
                    'intent_id' => $dataId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $amount = isset($body['amount']) ? (int) $body['amount'] / 100 : 0;

        $this->intentRepository->create([
            'intent_id' => $dataId,
            'device_id' => $deviceId,
            'external_ref' => $body['additional_info']['external_reference'] ?? '',
            'amount' => $amount,
            'intent_status' => $state,
            'ticket_number' => $body['additional_info']['ticket_number'] ?? null,
            'created_at' => DateHelper::formatForDb($body['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_by' => 'webhook',
        ]);

        $this->logger->info('Intent created from webhook', [
            'intent_id' => $dataId,
            'state' => $state,
            'amount' => $amount,
        ]);
    }

    private function processPayment(array $body, string $dataId, int $paymentId): void
    {
        $this->logger->info('Processing payment for intent', [
            'intent_id' => $dataId,
            'payment_id' => $paymentId,
        ]);

        try {
            $paymentData = $this->mpClient->getPayment($paymentId);
        } catch (\Throwable $e) {
            $this->logger->error('Could not fetch payment from MP API', [
                'payment_id' => $paymentId,
                'message' => $e->getMessage(),
            ]);
            return;
        }

        $existingPayment = $this->paymentRepository->findByPaymentId($paymentId);
        $paymentInternalId = null;

        if ($existingPayment === null) {
            $terminalId = null;
            $deviceId = $body['device_id'] ?? '';
            if ($deviceId !== '') {
                $terminal = $this->terminalRepository->findByExternalId($deviceId);
                $terminalId = $terminal['id'] ?? null;
            }

            $paymentInternalId = $this->paymentRepository->create(PaymentDataMapper::mapPaymentData(
                $paymentData,
                $dataId,
                $terminalId,
            ));
        } else {
            $paymentInternalId = $existingPayment['id'];
            $this->paymentRepository->update($paymentId, array_merge(
                PaymentDataMapper::mapPaymentUpdate($paymentData),
                ['updated_by' => 'webhook'],
            ));
        }

        if (isset($paymentData['charges_details']) && is_array($paymentData['charges_details'])) {
            $this->paymentRepository->insertCharges(
                $paymentInternalId,
                $paymentId,
                PaymentDataMapper::mapCharges($paymentData['charges_details'])
            );
        }

        $this->intentRepository->updateWithPayment($dataId, [
            'payment_id' => $paymentId,
            'payment_method' => $paymentData['payment_method_id'] ?? null,
            'payment_status' => $paymentData['status'] ?? null,
            'payment_detail' => $paymentData['status_detail'] ?? null,
            'installments' => $paymentData['installments'] ?? null,
            'updated_by' => 'webhook',
        ]);

        $this->logger->info('Intent payment processed', [
            'payment_id' => $paymentId,
            'intent_id' => $dataId,
        ]);
    }
}
