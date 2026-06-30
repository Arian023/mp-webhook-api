<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('orders', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('order_id', 'string', ['limit' => 50, 'null' => false, 'comment' => 'MP order ID (ORD...)'])
            ->addColumn('external_ref', 'string', ['limit' => 64, 'null' => false, 'comment' => 'External reference'])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'comment' => 'Total amount in ARS'])
            ->addColumn('status', 'string', ['limit' => 50, 'null' => false, 'comment' => 'Order status: created, processed, canceled, failed, refunded'])
            ->addColumn('status_detail', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Status detail: created, accredited, canceled, etc.'])
            ->addColumn('device_id', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Terminal device ID'])
            ->addColumn('payment_transaction_id', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Payment transaction ID (PAY...)'])
            ->addColumn('payment_status', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Payment status: processed, failed, refunded'])
            ->addColumn('payment_status_detail', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Payment status detail: accredited, etc.'])
            ->addColumn('payment_method_type', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Payment method type: credit_card, debit_card'])
            ->addColumn('payment_method_id', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Payment method / card brand: visa, master'])
            ->addColumn('installments', 'smallinteger', ['null' => true, 'signed' => false, 'comment' => 'Number of installments'])
            ->addColumn('ticket_number', 'string', ['limit' => 20, 'null' => true, 'comment' => 'Ticket / invoice number'])
            ->addColumn('print_on_terminal', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Print on terminal setting'])
            ->addColumn('description', 'string', ['limit' => 150, 'null' => true, 'comment' => 'Order description'])
            ->addColumn('action', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Last webhook action received'])
            ->addColumn('is_live', 'char', ['limit' => 1, 'null' => true, 'comment' => 'S=production, N=sandbox'])
            ->addColumn('created_date', 'datetime', ['null' => false, 'comment' => 'Order creation from MP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'string', ['limit' => 10, 'null' => false, 'default' => 'api'])
            ->addIndex(['order_id'], ['unique' => true])
            ->create();
    }
}
