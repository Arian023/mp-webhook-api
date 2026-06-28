<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTerminalsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('terminals', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('external_id', 'string', ['limit' => 50, 'null' => false, 'comment' => 'Device ID from MP (model + serial)'])
            ->addColumn('pos_id', 'string', ['limit' => 50, 'null' => true, 'comment' => 'POS ID in MP'])
            ->addColumn('branch_code', 'smallinteger', ['null' => true, 'signed' => false, 'comment' => 'Branch number'])
            ->addColumn('register_number', 'smallinteger', ['null' => true, 'signed' => false, 'comment' => 'Cash register number'])
            ->addColumn('operating_mode', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Operating mode'])
            ->addColumn('description', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Model / description'])
            ->addColumn('serial_number', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Device serial number'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_by', 'string', ['limit' => 40, 'null' => false])
            ->addIndex(['external_id'], ['unique' => true])
            ->create();
    }
}
