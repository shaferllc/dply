<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job history — the one thing reading a queue store can never provide.
 *
 * A processed job deletes its own row the moment it succeeds, so by the time
 * anything polls, the evidence is gone. These rows come from the in-app agent
 * reporting Laravel's queue events, which is the only vantage point that exists
 * at the moment a job finishes.
 *
 * Deliberately not "every job dply sees": rows are written per RUN, and a run
 * is bounded by real work rather than by wall-clock, so storage scales with a
 * site's job volume and the retention window rather than without limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_queue_job_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();

            $table->string('job_id')->nullable();
            $table->string('name');
            $table->string('queue')->nullable();
            $table->string('connection')->nullable();
            // processed | failed. Queued events are not stored: a waiting job is
            // already visible in the store, and recording both doubles the write
            // volume to say the same thing twice.
            $table->string('status', 16);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempts')->nullable();
            $table->string('exception')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            // The read path is "this site, newest first", optionally filtered to
            // failures or one job class.
            $table->index(['site_id', 'ran_at']);
            $table->index(['site_id', 'status', 'ran_at']);
            $table->index(['site_id', 'name', 'ran_at']);
            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_queue_job_runs');
    }
};
