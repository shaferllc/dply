<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integration_outbound_webhooks') && ! Schema::hasTable('notification_webhook_destinations')) {
            Schema::rename('integration_outbound_webhooks', 'notification_webhook_destinations');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_webhook_destinations') && ! Schema::hasTable('integration_outbound_webhooks')) {
            Schema::rename('notification_webhook_destinations', 'integration_outbound_webhooks');
        }
    }
};
