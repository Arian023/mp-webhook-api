<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Repositories\WebhookLogRepository;
use App\Infrastructure\Database;
use Slim\Exception\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Core webhook orchestrator.
 *
 * Receives raw HTTP requests from Mercado Pago, validates the HMAC-SHA256
 * signature, checks the JSON structure, dispatches to the appropriate handler
 * (IntentWebhookHandler, PaymentWebhookHandler, or OrderWebhookHandler),
 * and logs every event to the webhook_logs table. Processing failures return
 * 503 so Mercado Pago can retry; all domain writes share one transaction.
 */
class WebhookProcessor
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WebhookLogRepository $webhookLogRepository,
        private readonly IntentWebhookHandler $intentHandler,
        private readonly PaymentWebhookHandler $paymentHandler,
        private readonly OrderWebhookHandler $orderHandler,
        private readonly Database $database,
    ) {
    }

    public function process(ServerRequestInterface $request): array
    {
        $startTime = microtime(true);

        // PHP converts dots in query keys to underscores — use the raw query string.
        $rawQuery = $request->getUri()->getQuery();
        $dataId = $this->extractQueryParam($rawQuery, 'data.id');
        $type = $this->extractQueryParam($rawQuery, 'type');

        $decoded = json_decode((string) $request->getBody(), true);
        $body = is_array($decoded) ? $decoded : [];
        $this->logger->info('Webhook received', ['type' => $type, 'data_id' => $dataId]);

        $signatureValid = $this->isAuthorized($request, $dataId);

        if (!$signatureValid) {
            $this->logger->warning('Webhook signature invalid', ['data_id' => $dataId]);
        }

        $valid = preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $dataId) === 1
            && ($type !== 'payment' || filter_var($dataId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            && $this->isValid($body, $type);
        if ($valid && $type === 'point_integration_wh') {
            $valid = $body['id'] === $dataId;
        }
        if ($valid && in_array($type, ['order', 'orders'], true)) {
            $valid = $body['data']['id'] === $dataId;
        }
        $responseCode = 200;
        $failure = null;

        if ($valid && $signatureValid) {
            try {
                $this->database->beginTransaction();
                match ($type) {
                    'point_integration_wh' => $this->intentHandler->handle($body, $dataId),
                    'payment' => $this->paymentHandler->handle($body, $dataId),
                    'order', 'orders' => $this->orderHandler->handle($body, $dataId),
                    default => $this->logger->warning('Unknown webhook type', ['type' => $type]),
                };
                $this->database->commit();
            } catch (\Throwable $e) {
                $failure = $e;
                $responseCode = 503;
                if ($this->database->inTransaction()) {
                    $this->database->rollback();
                }
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

        if ($failure !== null) {
            throw new HttpException($request, 'Webhook processing failed', 503, $failure);
        }

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
            $skipValidation = ($_ENV['SKIP_WEBHOOK_VALIDATION'] ?? '') === 'true';

            if ($skipValidation) {
                $this->logger->warning('MP_WEBHOOK_SECRET not configured — validation skipped via SKIP_WEBHOOK_VALIDATION');
                return true;
            }

            $this->logger->critical('MP_WEBHOOK_SECRET not configured — rejecting unsigned webhook');
            return false;
        }

        $signatureHeader = $request->getHeaderLine('X-Signature');
        $requestId = $request->getHeaderLine('X-Request-Id');

        if ($signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                return false;
            }
            [$key, $value] = $pair;
            if (isset($parts[$key])) {
                return false;
            }
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if (!ctype_digit($ts) || !preg_match('/^[a-f0-9]{64}$/', $v1) || $requestId === '' || $dataId === '') {
            return false;
        }

        // Replay protection: reject timestamps older than 5 minutes
        // ts can be seconds (Point) or milliseconds (other APIs)
        $tsValue = (int) $ts;
        $tsInSeconds = $tsValue > 1_000_000_000_00 ? (int) ($tsValue / 1000) : $tsValue; // normalize ms to s
        if (abs(time() - $tsInSeconds) > 300) {
            $this->logger->warning('Webhook replay detected — timestamp too old', ['ts' => $ts]);
            return false;
        }

        $manifest = 'id:' . strtolower($dataId) . ";request-id:{$requestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, (string) $secret);

        return hash_equals($expected, $v1);
    }

    private function isValid(array $body, string $type): bool
    {
        return match ($type) {
            'point_integration_wh' => $this->validateIntentBody($body),
            'payment' => $this->validatePaymentBody($body),
            'order', 'orders' => $this->validateOrderBody($body),
            default => false,
        };
    }

    private function validateIntentBody(array $body): bool
    {
        if (!isset($body['id']) || !is_string($body['id']) || $body['id'] === '') {
            $this->logger->warning('Invalid intent body: missing id');
            return false;
        }
        if (!isset($body['amount']) || filter_var($body['amount'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            $this->logger->warning('Invalid intent body: missing or invalid amount');
            return false;
        }
        if (!isset($body['state']) || !is_string($body['state']) || $body['state'] === '') {
            $this->logger->warning('Invalid intent body: missing state');
            return false;
        }
        if (isset($body['payment']) && (!is_array($body['payment'])
            || (isset($body['payment']['id']) && filter_var($body['payment']['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false))) {
            return false;
        }
        if (isset($body['device_id']) && (!is_string($body['device_id']) || strlen($body['device_id']) > 100)) {
            return false;
        }
        return ! (isset($body['additional_info']) && !is_array($body['additional_info']))



        ;
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

    private function validateOrderBody(array $body): bool
    {
        if (!isset($body['action']) || !is_string($body['action']) || $body['action'] === '') {
            $this->logger->warning('Invalid order body: missing action');
            return false;
        }
        if (!isset($body['data']) || !is_array($body['data']) || !isset($body['data']['id']) || !is_string($body['data']['id']) || $body['data']['id'] === '') {
            $this->logger->warning('Invalid order body: missing data.id');
            return false;
        }

        return true;
    }

    private function extractQueryParam(string $rawQuery, string $key): string
    {
        // Manual parsing — parse_str converts dots to underscores
        foreach (explode('&', $rawQuery) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && urldecode($parts[0]) === $key) {
                return urldecode($parts[1]);
            }
        }
        return '';
    }
}
