<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Repositories\WebhookLogRepository;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Core webhook orchestrator.
 *
 * Receives raw HTTP requests from Mercado Pago, validates the HMAC-SHA256
 * signature, checks the JSON structure, dispatches to the appropriate handler
 * (IntentWebhookHandler or PaymentWebhookHandler), and logs every event to
 * the webhook_logs table. Always returns 200 — MP retries on non-2xx.
 */
class WebhookProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WebhookLogRepository $webhookLogRepository,
        private readonly IntentWebhookHandler $intentHandler,
        private readonly PaymentWebhookHandler $paymentHandler,
    ) {
    }

    public function process(ServerRequestInterface $request): array
    {
        $startTime = microtime(true);
        $queryParams = $request->getQueryParams();
        $dataId = $queryParams['data.id'] ?? '';
        $type = $queryParams['type'] ?? '';

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $this->logger->info('Webhook received', ['type' => $type, 'data_id' => $dataId]);
        $this->logger->debug('Webhook body', ['body' => $body]);

        $signatureValid = $this->isAuthorized($request, $dataId);

        if (!$signatureValid) {
            $this->logger->warning('Webhook signature invalid', ['data_id' => $dataId]);
        }

        $valid = $this->isValid($body, $type);
        $responseCode = 200;

        if ($valid && $signatureValid) {
            try {
                match ($type) {
                    'point_integration_wh' => $this->intentHandler->handle($body, $dataId),
                    'payment' => $this->paymentHandler->handle($body, $dataId),
                    default => $this->logger->warning('Unknown webhook type', ['type' => $type]),
                };
            } catch (\Throwable $e) {
                $this->logger->error('Webhook handler error', [
                    'type' => $type,
                    'data_id' => $dataId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $requestId = $request->getHeaderLine('X-Request-Id');
        $this->webhookLogRepository->log([
            'request_id' => $requestId,
            'event_type' => $type,
            'action' => $body['action'] ?? null,
            'data_id' => $dataId,
            'payload' => json_encode($body),
            'signature_valid' => $signatureValid ? 1 : 0,
            'response_code' => $responseCode,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->logger->info('Webhook processed', [
            'type' => $type,
            'data_id' => $dataId,
            'elapsed' => $elapsed . ' seg',
        ]);

        return [
            'message' => 'Webhook processed',
            'type' => $type,
        ];
    }

    private function isAuthorized(ServerRequestInterface $request, string $dataId): bool
    {
        $secret = $_ENV['MP_WEBHOOK_SECRET'] ?? '';
        if ($secret === '') {
            $this->logger->warning('MP_WEBHOOK_SECRET not configured — skipping signature validation');
            return true;
        }

        $signatureHeader = $request->getHeaderLine('X-Signature');
        $requestId = $request->getHeaderLine('X-Request-Id');

        if ($signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = explode('=', mb_trim($part), 2);
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if ($ts === '' || $v1 === '') {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, (string) $secret);

        return hash_equals($expected, $v1);
    }

    private function isValid(array $body, string $type): bool
    {
        return match ($type) {
            'point_integration_wh' => $this->validateIntentBody($body),
            'payment' => $this->validatePaymentBody($body),
            default => false,
        };
    }

    private function validateIntentBody(array $body): bool
    {
        if (empty($body['id'])) {
            $this->logger->warning('Invalid intent body: missing id');
            return false;
        }
        if (!isset($body['amount']) || !is_numeric($body['amount'])) {
            $this->logger->warning('Invalid intent body: missing or invalid amount');
            return false;
        }
        if (empty($body['created_at'])) {
            $this->logger->warning('Invalid intent body: missing created_at');
            return false;
        }
        if (empty($body['state'])) {
            $this->logger->warning('Invalid intent body: missing state');
            return false;
        }

        return true;
    }

    private function validatePaymentBody(array $body): bool
    {
        if (empty($body['id'])) {
            $this->logger->warning('Invalid payment body: missing id');
            return false;
        }
        if (empty($body['date_created'])) {
            $this->logger->warning('Invalid payment body: missing date_created');
            return false;
        }
        if (empty($body['type'])) {
            $this->logger->warning('Invalid payment body: missing type');
            return false;
        }

        return true;
    }
}
