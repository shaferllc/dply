<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Surfaces cron lines that Dply installs in its own dedicated
     * crontab blocks (currently: the metrics push agent's
     * `BEGIN DPLY METRICS GUEST` block) inside the standard cron list
     * so operators can see what is actually scheduled. system_managed
     * rows are read-only in the UI and skipped by ServerCronSynchronizer
     * (the metrics block has its own deploy job).
     *
     * `managed_block` identifies which dply-owned block on the host
     * mirrors this row, so future system-managed cron flows (e.g.
     * runtime hardening reminders) can hang off the same column.
     */
    public function up(): void
    {
        Schema::table('server_cron_jobs', function (Blueprint $table): void {
            $table->boolean('system_managed')->default(false)->after('enabled');
            $table->string('managed_block', 32)->nullable()->after('system_managed');
            $table->string('managed_signature', 64)->nullable()->after('managed_block');

            // Idempotency: a single (server, signature) tuple should
            // never produce two rows. The recorder upserts on this.
            $table->unique(['server_id', 'managed_signature'], 'server_cron_jobs_server_signature_unique');
        });
    }

    public function down(): void
    {
        Schema::table('server_cron_jobs', function (Blueprint $table): void {
            $table->dropUnique('server_cron_jobs_server_signature_unique');
            $table->dropColumn(['system_managed', 'managed_block', 'managed_signature']);
        });
    }
};
