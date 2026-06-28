<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateIntentsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('intents', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('intent_id', 'string', ['limit' => 50, 'null' => false, 'comment' => 'MP intent UUID'])
            ->addColumn('device_id', 'string', ['limit' => 50, 'null' => false, 'comment' => 'Terminal device_id'])
            ->addColumn('external_ref', 'string', ['limit' => 50, 'null' => false, 'comment' => 'External reference (register-invoice)'])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'comment' => 'Total amount in ARS'])
            ->addColumn('intent_status', 'string', ['limit' => 50, 'null' => false, 'comment' => 'OPEN, FINISHED, CANCELED, ABANDONED, ERROR'])
            ->addColumn('reject_reason', 'string', ['limit' => 1, 'null' => true, 'comment' => 'C=installments, M=amount, F=payment method, X=other'])
            ->addColumn('payment_method', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Payment method name'])
            ->addColumn('installments', 'smallinteger', ['null' => true, 'signed' => false, 'comment' => 'Number of installments'])
            ->addColumn('payment_status', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Payment status from MP'])
            ->addColumn('payment_detail', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Payment status detail'])
            ->addColumn('payment_id', 'biginteger', ['null' => true, 'signed' => false, 'comment' => 'MP payment ID (11 digits)'])
            ->addColumn('ticket_number', 'string', ['limit' => 20, 'null' => true, 'comment' => 'Invoice number'])
            ->addColumn('register_number', 'smallinteger', ['null' => true, 'signed' => false, 'comment' => 'Cash register number'])
            ->addColumn('created_at', 'datetime', ['null' => false, 'comment' => 'Intent creation timestamp'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'string', ['limit' => 10, 'null' => false, 'default' => 'api'])
            ->addIndex(['intent_id'], ['unique' => true])
            ->addIndex(['device_id'])
            ->addIndex(['external_ref'])
            ->addIndex(['payment_id'])
            ->create();
    }
}
