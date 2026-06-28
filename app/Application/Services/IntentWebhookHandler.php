<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Helpers\DateHelper;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\IntentRepository;
use App\Infrastructure\Repositories\PaymentRepository;
use App\Infrastructure\Repositories\TerminalRepository;
use Psr\Log\LoggerInterface;

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

            $paymentInternalId = $this->paymentRepository->create($this->mapPaymentData(
                $paymentData,
                $dataId,
                $terminalId
            ));
        } else {
            $paymentInternalId = $existingPayment['id'];
            $this->paymentRepository->update($paymentId, $this->mapPaymentUpdate($paymentData));
        }

        if (isset($paymentData['charges_details']) && is_array($paymentData['charges_details'])) {
            $this->paymentRepository->insertCharges(
                $paymentInternalId,
                $paymentId,
                $this->mapCharges($paymentData['charges_details'])
            );
        }

        $this->intentRepository->updateWithPayment($dataId, [
            'payment_id' => $paymentId,
            'payment_method' => $paymentData['payment_method_id'] ?? null,
            'payment_status' => $paymentData['status'] ?? null,
            'payment_detail' => $paymentData['status_detail'] ?? null,
            'installments' => $paymentData['installments'] ?? null,
        ]);

        $this->logger->info('Order processed', [
            'payment_id' => $paymentId,
            'intent_id' => $dataId,
        ]);
    }

    private function mapPaymentData(array $mp, string $intentId, ?int $terminalId): array
    {
        $poi = $mp['point_of_interaction'] ?? [];
        $card = $mp['card'] ?? [];
        $details = $mp['transaction_details'] ?? [];

        return [
            'payment_id' => $mp['id'],
            'intent_id' => $intentId,
            'terminal_id' => $terminalId,
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
            'updated_by' => 'webhook',
        ];
    }

    private function mapPaymentUpdate(array $mp): array
    {
        return [
            'status' => $mp['status'] ?? '',
            'status_detail' => $mp['status_detail'] ?? '',
            'release_status' => $mp['money_release_status'] ?? null,
            'released_at' => isset($mp['money_release_date']) ? DateHelper::toLocal($mp['money_release_date']) : null,
            'updated_by' => 'webhook',
        ];
    }

    private function mapCharges(array $charges): array
    {
        $mapped = [];
        foreach ($charges as $charge) {
            $mapped[] = [
                'charge_id' => $charge['id'] ?? '',
                'type' => $charge['type'] ?? null,
                'description' => $charge['name'] ?? null,
                'amount' => $charge['amounts']['original'] ?? null,
                'refunded' => $charge['amounts']['refunded'] ?? 0,
                'rate' => $charge['rate'] ?? null,
                'from_account' => $charge['accounts']['from'] ?? null,
                'to_account' => $charge['accounts']['to'] ?? null,
                'created_at_mp' => isset($charge['date_created']) ? DateHelper::toLocal($charge['date_created']) : null,
            ];
        }
        return $mapped;
    }
}
