<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A continuous span where a monitor was not operational. Opened on the
 * down/degraded transition and closed on recovery — the same edge where the
 * job publishes the notification. `resolved_at` null means ongoing. Kept
 * indefinitely (small, valuable); powers the incident timeline and a future
 * public status-page incident feed. site_id is denormalised for site-wide reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_uptime_incidents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_uptime_monitor_id')
                ->constrained('site_uptime_monitors')
                ->cascadeOnDelete();
            $table->foreignUlid('site_id')
                ->constrained('sites')
                ->cascadeOnDelete();
            $table->string('severity', 16); // degraded | outage
            $table->string('cause', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['site_uptime_monitor_id', 'resolved_at']);
            $table->index(['site_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_uptime_incidents');
    }
};
