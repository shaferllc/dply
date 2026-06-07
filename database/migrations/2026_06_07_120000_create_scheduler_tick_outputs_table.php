<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling per-scheduler tick output history (schedule-page-v2, PR2).
 *
 * One row per recorded scheduler run. Failures are always recorded (the wrapper
 * emits a stderr excerpt for free on failure); successful-run output is only
 * recorded when per-scheduler capture is enabled (control file present on the
 * box). Run-now invocations are recorded with trigger='manual'.
 *
 * Retention is count-based (keep newest N per heartbeat), pruned inline on
 * write. Each stream is capped to 16KB at the writer. See
 * [[project_schedule_mirrors_workers]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_tick_outputs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('server_scheduler_heartbeat_id')
                ->constrained('server_scheduler_heartbeats')
                ->cascadeOnDelete();

            // 'cron' = real scheduled tick (via ingest), 'manual' = Run-now.
            $table->string('trigger')->default('cron');

            $table->integer('exit_code')->nullable();
            $table->integer('duration_ms')->nullable();

            // Capped to 16KB each at the writer.
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();

            $table->timestamp('ran_at')->nullable();
            $table->timestamps();

            // Newest-first per scheduler — the prune + list queries both order
            // by this.
            $table->index(['server_scheduler_heartbeat_id', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_tick_outputs');
    }
};
