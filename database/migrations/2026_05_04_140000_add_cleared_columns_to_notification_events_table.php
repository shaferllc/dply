<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_events', function (Blueprint $table): void {
            // Soft-archive flag for resource notification panels. Lets
            // operators wipe noise from a server / project page without
            // losing the underlying event history (the dedicated inbox
            // at /notifications can still surface cleared rows).
            $table->timestampTz('cleared_at')->nullable()->after('occurred_at');
            $table->char('cleared_by_user_id', 26)->nullable()->after('cleared_at');

            $table->index(['resource_type', 'resource_id', 'cleared_at'], 'notif_events_resource_clear_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notification_events', function (Blueprint $table): void {
            $table->dropIndex('notif_events_resource_clear_idx');
            $table->dropColumn(['cleared_at', 'cleared_by_user_id']);
        });
    }
};
