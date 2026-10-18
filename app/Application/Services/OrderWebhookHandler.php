<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Helpers\DateHelper;
use App\Infrastructure\Helpers\PaymentDataMapper;
use App\Infrastructure\MercadoPago\MercadoPagoClient;
use App\Infrastructure\Repositories\OrderRepository;
use App\Infrastructure\Repositories\PaymentRepository;
use App\Infrastructure\Repositories\TerminalRepository;
use Psr\Log\LoggerInterface;

class OrderWebhookHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MercadoPagoClient $mpClient,
        private readonly OrderRepository $orderRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly TerminalRepository $terminalRepository,
    ) {
    }

    public function handle(array $body, string $dataId): void
    {
        $startTime = microtime(true);
        $action = $body['action'] ?? 'unknown';

        $this->logger->info('Handling order webhook', [
            'order_id' => $dataId,
            'action' => $action,
        ]);

        try {
            $orderData = $this->mpClient->getOrder($dataId);
        } catch (\Throwable $e) {
            $this->logger->error('Could not fetch order from MP API', [
                'order_id' => $dataId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $existingOrder = $this->orderRepository->findByOrderId($dataId);

        if ($existingOrder === null) {
            $this->orderRepository->create($this->mapOrderData($orderData, $action));
            $this->logger->info('Order created from webhook', [
                'order_id' => $dataId,
                'status' => $orderData['status'] ?? 'unknown',
            ]);
        } else {
            $this->orderRepository->update($dataId, [
                'status' => $orderData['status'] ?? $existingOrder['status'],
                'status_detail' => $orderData['status_detail'] ?? null,
                'action' => $action,
                'updated_by' => 'webhook',
            ]);
        }

        $payments = $orderData['transactions']['payments'] ?? [];
        if (count($payments) > 0) {
            foreach ($payments as $payment) {
                $this->syncPayment($orderData, $payment, $dataId);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->logger->info('Order webhook handled', [
            'order_id' => $dataId,
            'action' => $action,
            'elapsed' => $elapsed . ' seg',
        ]);
    }

    private function syncPayment(array $orderData, array $payment, string $orderId): void
    {
        $paymentRefId = $payment['reference_id'] ?? null;
        if ($paymentRefId === null || !is_numeric($paymentRefId)) {
            return;
        }

        $paymentId = (int) $paymentRefId;
        $existing = $this->paymentRepository->findByPaymentId($paymentId);

        $terminalId = null;
        $deviceId = $orderData['config']['point']['terminal_id'] ?? null;
        if ($deviceId !== null) {
            $terminal = $this->terminalRepository->findByExternalId($deviceId);
            $terminalId = $terminal['id'] ?? null;
        }

        $paymentMethod = $payment['payment_method'] ?? [];

        if ($existing === null) {
            $this->paymentRepository->create(array_merge(
                PaymentDataMapper::mapPaymentData(
                    [
                        'id' => $paymentId,
                        'external_reference' => $orderData['external_reference'] ?? '',
                        'status' => $payment['status'] ?? '',
                        'status_detail' => $payment['status_detail'] ?? '',
                        'payment_type_id' => $paymentMethod['type'] ?? null,
                        'payment_method_id' => $paymentMethod['id'] ?? null,
                        'transaction_amount' => $payment['amount'] ?? $orderData['total_amount'] ?? 0,
                        'installments' => $paymentMethod['installments'] ?? null,
                        'currency_id' => 'ARS',
                        'live_mode' => $orderData['live_mode'] ?? false,
                        'date_created' => $orderData['created_date'] ?? date('c'),
                        'date_approved' => ($payment['status'] ?? '') === 'processed' ? date('c') : null,
                        'point_of_interaction' => ['type' => 'POINT'],
                        'transaction_details' => [],
                        'card' => [],
                    ],
                    null,   // no intent_id
                    $terminalId,
                    $orderId   // order_id
                ),
                ['updated_by' => 'webhook']
            ));
        } else {
            $update = array_merge(
                PaymentDataMapper::mapPaymentUpdate([
                    'status' => $payment['status'] ?? '',
                    'status_detail' => $payment['status_detail'] ?? '',
                    'money_release_status' => null,
                    'money_release_date' => null,
                ]),
                ['updated_by' => 'webhook']
            );
            if (($payment['status'] ?? '') === 'processed' && ($existing['approved_at'] ?? null) === null) {
                $update['approved_at'] = date('Y-m-d H:i:s');
            }
            $this->paymentRepository->update($paymentId, $update);
        }
    }

    private function mapOrderData(array $orderData, string $action): array
    {
        $payments = $orderData['transactions']['payments'] ?? [];
        $firstPayment = $payments[0] ?? [];
        $paymentMethod = $firstPayment['payment_method'] ?? [];
        $pointConfig = $orderData['config']['point'] ?? [];

        return [
            'order_id' => $orderData['id'],
            'external_ref' => $orderData['external_reference'] ?? '',
            'amount' => (float) ($orderData['total_amount'] ?? 0),
            'status' => $orderData['status'] ?? 'created',
            'status_detail' => $orderData['status_detail'] ?? null,
            'device_id' => $pointConfig['terminal_id'] ?? null,
            'payment_transaction_id' => $firstPayment['id'] ?? null,
            'payment_status' => $firstPayment['status'] ?? null,
            'payment_status_detail' => $firstPayment['status_detail'] ?? null,
            'payment_method_type' => $paymentMethod['type'] ?? null,
            'payment_method_id' => $paymentMethod['id'] ?? null,
            'installments' => $paymentMethod['installments'] ?? null,
            'ticket_number' => $pointConfig['ticket_number'] ?? null,
            'print_on_terminal' => $pointConfig['print_on_terminal'] ?? null,
            'description' => $orderData['description'] ?? null,
            'action' => $action,
            'is_live' => ($orderData['live_mode'] ?? false) ? 'S' : 'N',
            'created_date' => DateHelper::formatForDb($orderData['created_date'] ?? date('c')),
            'updated_by' => 'webhook',
        ];
    }
}
