<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateChargesTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('charges', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('payment_id', 'integer', ['null' => false, 'signed' => false, 'comment' => 'FK to payments.id'])
            ->addColumn('charge_id', 'string', ['limit' => 30, 'null' => false, 'comment' => 'MP charge ID'])
            ->addColumn('payment_ref', 'biginteger', ['null' => false, 'signed' => false, 'comment' => 'MP payment ID'])
            ->addColumn('type', 'string', ['limit' => 30, 'null' => true, 'comment' => 'fee, tax'])
            ->addColumn('description', 'string', ['limit' => 80, 'null' => true, 'comment' => 'Charge name'])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'comment' => 'Original amount'])
            ->addColumn('refunded', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true, 'comment' => 'Refunded amount'])
            ->addColumn('rate', 'decimal', ['precision' => 6, 'scale' => 2, 'null' => true, 'comment' => 'Rate percentage'])
            ->addColumn('from_account', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Debited from'])
            ->addColumn('to_account', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Credited to'])
            ->addColumn('created_at_mp', 'datetime', ['null' => true, 'comment' => 'date_created from MP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['charge_id'], ['unique' => true])
            ->addIndex(['payment_id'])
            ->addIndex(['payment_ref'])
            ->addForeignKey('payment_id', 'payments', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();
    }
}
