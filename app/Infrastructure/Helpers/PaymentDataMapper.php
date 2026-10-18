<?php

declare(strict_types=1);

namespace App\Infrastructure\Helpers;

/**
 * Shared payment data mapping from MP API responses to DB columns.
 *
 * Extracted from duplicate copies in IntentWebhookHandler, PaymentWebhookHandler,
 * and IntentService. All three callers use the same field layout.
 */
class PaymentDataMapper
{
    /**
     * Map a full MP payment response to the payments table columns.
     *
     * @param array $mp        Raw MP API payment response
     * @param string|null $intentId   MP intent UUID (or null for order-only payments)
     * @param int|null    $terminalId Local terminal DB id
     * @param string|null $orderId    MP order ID (for order-based payments)
     *
     * @return array Key-value map for PaymentRepository::create()
     */
    public static function mapPaymentData(array $mp, ?string $intentId, ?int $terminalId = null, ?string $orderId = null): array
    {
        $poi = $mp['point_of_interaction'] ?? [];
        $card = $mp['card'] ?? [];
        $details = $mp['transaction_details'] ?? [];

        return [
            'payment_id' => $mp['id'],
            'intent_id' => $intentId,
            'order_id' => $orderId,
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
        ];
    }

    /**
     * Map only the fields that change on a payment update (status, release).
     */
    public static function mapPaymentUpdate(array $mp): array
    {
        return [
            'status' => $mp['status'] ?? '',
            'status_detail' => $mp['status_detail'] ?? '',
            'release_status' => $mp['money_release_status'] ?? null,
            'released_at' => isset($mp['money_release_date']) ? DateHelper::toLocal($mp['money_release_date']) : null,
        ];
    }

    /**
     * Map MP charges_details[] array to the charges table structure.
     *
     * @return array List of charge maps (each ready for batch insert)
     */
    public static function mapCharges(array $charges): array
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
