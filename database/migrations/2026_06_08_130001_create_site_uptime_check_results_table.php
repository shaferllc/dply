<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of every uptime check (scheduled or on-demand). The
 * monitor's last_* columns still hold the latest snapshot for fast reads; this
 * table powers uptime %, latency trends and incident stitching. Pruned at 90
 * days by PruneSiteUptimeCheckResultsCommand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_uptime_check_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_uptime_monitor_id')
                ->constrained('site_uptime_monitors')
                ->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->string('state', 16); // operational | degraded | outage
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('probe_worker', 64)->nullable();

            $table->index(['site_uptime_monitor_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_uptime_check_results');
    }
};
