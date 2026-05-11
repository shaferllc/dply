<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshots the `enabled` value at the moment of the last successful
     * crontab sync. Lets `toggleCronJob` detect "pause → resume" round-trips
     * and restore is_synced=true when the panel state matches the host again.
     *
     * Backfill: if a row was synced, we know the host reflects its current
     * enabled value; otherwise leave NULL so a future toggle plays it safe.
     */
    public function up(): void
    {
        Schema::table('server_cron_jobs', function (Blueprint $table): void {
            $table->boolean('last_synced_enabled')->nullable()->after('is_synced');
        });

        DB::table('server_cron_jobs')
            ->where('is_synced', true)
            ->update(['last_synced_enabled' => DB::raw('enabled')]);
    }

    public function down(): void
    {
        Schema::table('server_cron_jobs', function (Blueprint $table): void {
            $table->dropColumn('last_synced_enabled');
        });
    }
};
