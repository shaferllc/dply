<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q17 trust-window UX: emit a 72h paused-migration nudge before the 168h
 * auto-revoke. nudge_sent_at is stamped when the nudge is dispatched so the
 * notification fires once per migration, not every hour the expire-paused
 * command runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_server_migrations', function (Blueprint $table): void {
            $table->timestamp('paused_nudge_sent_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_server_migrations', function (Blueprint $table): void {
            $table->dropColumn('paused_nudge_sent_at');
        });
    }
};
