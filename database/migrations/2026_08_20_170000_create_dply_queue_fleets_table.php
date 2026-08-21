<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A managed worker fleet — dply-owned workers that drain one queue.
 *
 * One row per (namespace, queue name). The one-queue-per-fleet constraint is
 * not a simplification: the autoscaling signal (pending depth, in-flight
 * count, job duration) is only meaningful for a single queue, and a fleet
 * spanning two of them could not size itself for either.
 *
 * Lives in the primary database with the namespaces it belongs to, not on the
 * `dply_queue` connection — this is control-plane state, not job data.
 * See docs/adr/managed-queue-workers.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dply_queue_fleets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('namespace_id')->index();
            $table->foreignUlid('organization_id')->index();

            // The Laravel queue name this fleet drains. Denormalised from the
            // namespace so the autoscaler never has to resolve it per tick.
            $table->string('queue', 120);

            // flex: may sleep at zero, short job ceiling. pro: always on.
            $table->string('class', 16)->default('flex');
            $table->string('status', 24)->default('active');

            $table->unsignedInteger('memory_mib')->default(256);
            $table->unsignedInteger('min_workers')->default(0);
            $table->unsignedInteger('max_workers')->default(3);

            // Last count the autoscaler asked for, and what it saw when it
            // asked. Persisted so the tick is inspectable after the fact —
            // "why did this scale" is otherwise unanswerable.
            $table->unsignedInteger('desired_workers')->default(0);
            $table->unsignedInteger('quiet_ticks')->default(0);
            $table->timestamp('last_scaled_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // One fleet per queue name per namespace. A second fleet on the
            // same queue would have two autoscalers fighting over one signal.
            $table->unique(['namespace_id', 'queue']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dply_queue_fleets');
    }
};
