<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Helpers\DateHelper;
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
                        $this->mapPaymentData($paymentData, $intentId),
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

    public function refund(string $intentId, ?string $reason): array
    {
        $intent = $this->intentRepository->findByIntentId($intentId);

        if ($intent === null || $intent['payment_id'] === null) {
            throw new \RuntimeException("Intent {$intentId} has no associated payment");
        }

        $paymentId = (int) $intent['payment_id'];
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

        $this->logger->info('Payment refunded', [
            'intent_id' => $intentId,
            'payment_id' => $paymentId,
            'reason' => $reason,
        ]);

        return $response;
    }

    private function mapPaymentData(array $mp, string $intentId): array
    {
        $card = $mp['card'] ?? [];
        $details = $mp['transaction_details'] ?? [];
        $poi = $mp['point_of_interaction'] ?? [];

        return [
            'payment_id' => $mp['id'],
            'intent_id' => $intentId,
            'external_ref' => $mp['external_reference'] ?? '',
            'status' => $mp['status'] ?? '',
            'status_detail' => $mp['status_detail'] ?? '',
            'payment_type' => $mp['payment_type_id'] ?? null,
            'payment_method' => $mp['payment_method_id'] ?? null,
            'total_amount' => $mp['transaction_amount'] ?? 0,
            'net_received' => $details['net_received_amount'] ?? null,
            'overpaid_amount' => $details['overpaid_amount'] ?? null,
            'taxes_amount' => $mp['taxes_amount'] ?? null,
            'coupon_amount' => $mp['coupon_amount'] ?? null,
            'installment_amount' => $details['installment_amount'] ?? null,
            'installments' => $mp['installments'] ?? null,
            'currency' => $mp['currency_id'] ?? 'ARS',
            'authorization_code' => $mp['authorization_code'] ?? null,
            'card_bin' => $card['bin'] ?? null,
            'card_first_six' => $card['first_six_digits'] ?? null,
            'card_last_four' => $card['last_four_digits'] ?? null,
            'card_country' => $card['country'] ?? null,
            'holder_doc_type' => $card['cardholder']['identification']['type'] ?? null,
            'holder_doc_number' => isset($card['cardholder']['identification']['number'])
                ? (string) $card['cardholder']['identification']['number']
                : null,
            'holder_name' => $card['cardholder']['name'] ?? null,
            'created_at_mp' => DateHelper::toLocal($mp['date_created'] ?? date('c')),
            'approved_at' => isset($mp['date_approved']) ? DateHelper::toLocal($mp['date_approved']) : null,
            'release_status' => $mp['money_release_status'] ?? null,
            'released_at' => isset($mp['money_release_date']) ? DateHelper::toLocal($mp['money_release_date']) : null,
            'is_live' => ($mp['live_mode'] ?? false) ? 'S' : 'N',
            'device_serial' => $poi['device']['serial_number'] ?? null,
            'interaction_type' => $poi['type'] ?? 'POINT',
            'issuer_id' => $mp['issuer_id'] ?? null,
            'payer_id' => $mp['payer']['id'] ?? null,
            'store_id' => $mp['store_id'] ?? null,
            'pos_id' => $mp['pos_id'] ?? null,
        ];
    }
}
