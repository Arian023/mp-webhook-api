<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateWebhookLogsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('webhook_logs', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('request_id', 'string', ['limit' => 36, 'null' => false, 'comment' => 'X-Request-Id from MP'])
            ->addColumn('event_type', 'string', ['limit' => 50, 'null' => false, 'comment' => 'order, payment, etc.'])
            ->addColumn('action', 'string', ['limit' => 50, 'null' => true, 'comment' => 'order.processed, order.canceled...'])
            ->addColumn('data_id', 'string', ['limit' => 50, 'null' => false, 'comment' => 'data.id from query params'])
            ->addColumn('payload', 'json', ['null' => false, 'comment' => 'Full webhook body'])
            ->addColumn('signature_valid', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('response_code', 'smallinteger', ['null' => false, 'default' => 200])
            ->addColumn('processed_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['data_id'])
            ->addIndex(['event_type'])
            ->addIndex(['processed_at'])
            ->create();
    }
}
