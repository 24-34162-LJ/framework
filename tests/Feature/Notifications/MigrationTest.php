<?php

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesNotificationTables;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use CreatesNotificationTables;

    public function test_migration_adds_columns_and_table_on_sqlite(): void
    {
        $this->createNotificationTables();

        $this->assertTrue(Schema::hasColumns('users', ['phone', 'sms_enabled']));
        $this->assertTrue(Schema::hasColumns('notification_deliveries', [
            'delivery_id', 'notification_id', 'shipment_id', 'channel', 'recipient', 'subject',
            'message', 'status', 'provider_message_id', 'error', 'created_at', 'sent_at',
        ]));
    }
}
