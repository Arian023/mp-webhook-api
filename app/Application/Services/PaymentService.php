<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Helpers\DateHelper;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\PaymentRepository;
use Psr\Log\LoggerInterface;

/**
 * Payment operations (get, refund) via the MP Payments API.
 *
 * Used by the /api/payments endpoints. Fetches payment details from
 * MP and keeps the local DB in sync on each call.
 */
class PaymentService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MercadoPagoClient $mpClient,
        private readonly PaymentRepository $paymentRepository,
    ) {
    }

    public function get(int $paymentId): array
    {
        $paymentData = $this->mpClient->getPayment($paymentId);

        $existing = $this->paymentRepository->findByPaymentId($paymentId);
        if ($existing !== null) {
            $this->paymentRepository->update($paymentId, [
                'status' => $paymentData['status'] ?? '',
                'status_detail' => $paymentData['status_detail'] ?? '',
                'release_status' => $paymentData['money_release_status'] ?? null,
                'released_at' => isset($paymentData['money_release_date'])
                    ? DateHelper::toLocal($paymentData['money_release_date'])
                    : null,
                'updated_by' => 'api',
            ]);
        }

        return $paymentData;
    }

    public function refund(int $paymentId): array
    {
        $response = $this->mpClient->refundPayment($paymentId);

        $paymentData = $this->mpClient->getPayment($paymentId);
        $this->paymentRepository->update($paymentId, [
            'status' => $paymentData['status'] ?? '',
            'status_detail' => $paymentData['status_detail'] ?? '',
            'release_status' => $paymentData['money_release_status'] ?? null,
            'released_at' => isset($paymentData['money_release_date'])
                ? DateHelper::toLocal($paymentData['money_release_date'])
                : null,
            'updated_by' => 'api',
        ]);

        $this->logger->info('Payment refunded', ['payment_id' => $paymentId]);

        return $response;
    }
}
