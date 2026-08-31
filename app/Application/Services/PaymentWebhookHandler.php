<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Helpers\PaymentDataMapper;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\IntentRepository;
use App\Infrastructure\Repositories\PaymentRepository;
use App\Infrastructure\Repositories\TerminalRepository;
use Psr\Log\LoggerInterface;

/**
 * Handles payment-type webhooks (legacy, rarely used).
 *
 * This is the older webhook type where MP sends a direct payment
 * notification instead of an intent notification. The handler fetches
 * the full payment from the MP API, stores it, and updates any related
 * intent via metadata.payment_intent_id.
 */
class PaymentWebhookHandler
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
        $paymentId = (int) $dataId;

        $this->logger->info('Handling payment webhook', ['payment_id' => $paymentId]);

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
        $intentId = $paymentData['metadata']['payment_intent_id'] ?? null;

        if ($existingPayment === null) {
            $terminalId = null;
            $deviceSerial = $paymentData['point_of_interaction']['device']['serial_number'] ?? null;
            if ($deviceSerial !== null) {
                $terminal = $this->terminalRepository->findByExternalId($deviceSerial);
                $terminalId = $terminal['id'] ?? null;
            }

            $this->paymentRepository->create(array_merge(
                PaymentDataMapper::mapPaymentData($paymentData, $intentId, $terminalId),
                ['updated_by' => 'webhook']
            ));
        } else {
            $this->paymentRepository->update($paymentId, array_merge(
                PaymentDataMapper::mapPaymentUpdate($paymentData),
                ['updated_by' => 'webhook'],
            ));
        }

        if (isset($paymentData['charges_details']) && is_array($paymentData['charges_details'])) {
            $internalId = $existingPayment['id'] ?? null;
            if ($internalId === null) {
                $found = $this->paymentRepository->findByPaymentId($paymentId);
                $internalId = $found['id'] ?? null;
            }
            if ($internalId !== null) {
                $this->paymentRepository->insertCharges(
                    $internalId,
                    $paymentId,
                    PaymentDataMapper::mapCharges($paymentData['charges_details'])
                );
            }
        }

        if ($intentId !== null) {
            $this->intentRepository->updateWithPayment($intentId, [
                'payment_id' => $paymentId,
                'payment_method' => $paymentData['payment_method_id'] ?? null,
                'payment_status' => $paymentData['status'] ?? null,
                'payment_detail' => $paymentData['status_detail'] ?? null,
                'installments' => $paymentData['installments'] ?? null,
                'updated_by' => 'webhook',
            ]);
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->logger->info('Payment webhook handled', [
            'payment_id' => $paymentId,
            'elapsed' => $elapsed . ' seg',
        ]);
    }
}
