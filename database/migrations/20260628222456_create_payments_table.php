<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePaymentsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('payments', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('payment_id', 'biginteger', ['null' => false, 'signed' => false, 'comment' => 'MP payment ID (11 digits)'])
            ->addColumn('intent_id', 'string', ['limit' => 50, 'null' => true, 'comment' => 'MP intent UUID'])
            ->addColumn('terminal_id', 'integer', ['null' => true, 'signed' => false, 'comment' => 'FK to terminals.id'])
            ->addColumn('external_ref', 'string', ['limit' => 50, 'null' => false, 'comment' => 'External reference'])
            ->addColumn('status', 'string', ['limit' => 50, 'null' => false, 'comment' => 'approved, rejected, refunded, cancelled'])
            ->addColumn('status_detail', 'string', ['limit' => 50, 'null' => false, 'comment' => 'e.g. accredited'])
            ->addColumn('payment_type', 'string', ['limit' => 50, 'null' => true, 'comment' => 'credit_card, debit_card, account_money'])
            ->addColumn('payment_method', 'string', ['limit' => 50, 'null' => true, 'comment' => 'visa, debvisa, account_money'])
            ->addColumn('register_number', 'smallinteger', ['null' => true, 'signed' => false, 'comment' => 'Cash register number'])
            ->addColumn('card_number', 'smallinteger', ['null' => true, 'signed' => false, 'comment' => 'Card number (tac)'])
            ->addColumn('total_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'comment' => 'Total in ARS'])
            ->addColumn('net_received', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'comment' => 'Net received'])
            ->addColumn('overpaid_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'comment' => 'Overpaid amount'])
            ->addColumn('taxes_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'comment' => 'Taxes amount'])
            ->addColumn('coupon_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'comment' => 'Coupon discount'])
            ->addColumn('installment_amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'comment' => 'Per-installment amount'])
            ->addColumn('installments', 'smallinteger', ['null' => true, 'signed' => false, 'comment' => 'Number of installments'])
            ->addColumn('currency', 'string', ['limit' => 5, 'null' => true, 'default' => 'ARS'])
            ->addColumn('authorization_code', 'string', ['limit' => 6, 'null' => true, 'comment' => 'Authorization code'])
            ->addColumn('card_bin', 'string', ['limit' => 8, 'null' => true, 'comment' => 'Card BIN'])
            ->addColumn('card_first_six', 'string', ['limit' => 6, 'null' => true, 'comment' => 'First 6 digits'])
            ->addColumn('card_last_four', 'string', ['limit' => 4, 'null' => true, 'comment' => 'Last 4 digits'])
            ->addColumn('card_country', 'string', ['limit' => 50, 'null' => true, 'default' => 'ARG'])
            ->addColumn('holder_doc_type', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Cardholder ID type'])
            ->addColumn('holder_doc_number', 'string', ['limit' => 20, 'null' => true, 'comment' => 'Cardholder ID number'])
            ->addColumn('holder_name', 'string', ['limit' => 60, 'null' => true, 'comment' => 'Cardholder name'])
            ->addColumn('created_at_mp', 'datetime', ['null' => false, 'comment' => 'date_created from MP'])
            ->addColumn('approved_at', 'datetime', ['null' => true, 'comment' => 'date_approved from MP'])
            ->addColumn('release_status', 'string', ['limit' => 50, 'null' => true, 'comment' => 'pending, released'])
            ->addColumn('released_at', 'datetime', ['null' => true, 'comment' => 'Money release date'])
            ->addColumn('is_live', 'string', ['limit' => 1, 'null' => true, 'comment' => 'S=production, N=sandbox'])
            ->addColumn('device_serial', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Device serial number'])
            ->addColumn('interaction_type', 'string', ['limit' => 50, 'null' => true, 'default' => 'POINT', 'comment' => 'POINT or INSTORE'])
            ->addColumn('issuer_id', 'biginteger', ['null' => true, 'signed' => false, 'comment' => 'Card issuer ID'])
            ->addColumn('payer_id', 'biginteger', ['null' => true, 'signed' => false, 'comment' => 'Payer ID'])
            ->addColumn('store_id', 'biginteger', ['null' => true, 'signed' => false, 'comment' => 'Store ID'])
            ->addColumn('pos_id', 'biginteger', ['null' => true, 'signed' => false, 'comment' => 'POS ID'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'string', ['limit' => 10, 'null' => false, 'default' => 'api'])
            ->addIndex(['payment_id'], ['unique' => true])
            ->addIndex(['intent_id'])
            ->addIndex(['external_ref'])
            ->addIndex(['terminal_id'])
            ->create();
    }
}
