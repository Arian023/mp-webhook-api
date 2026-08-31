<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddOrderIdToPayments extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('payments');
        $table
            ->addColumn('order_id', 'string', ['limit' => 50, 'null' => true, 'after' => 'intent_id', 'comment' => 'MP order ID (for order-based payments)'])
            ->addIndex(['order_id'])
            ->update();
    }
}