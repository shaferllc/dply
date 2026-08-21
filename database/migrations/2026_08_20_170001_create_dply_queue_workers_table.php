<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One managed queue worker — a single container's whole life.
 *
 * This table is the billing record, which is why a row outlives the container
 * it describes: worker-seconds are invoiced from `started_at`/`stopped_at`,
 * so a stopped worker is retained until it has been rolled up, not deleted on
 * teardown. `runtime_ref` is whatever the runtime needs to find the container
 * again (a Docker id today) and is deliberately opaque to everything else.
 *
 * See docs/adr/managed-queue-workers.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dply_queue_workers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('fleet_id')->index();

            $table->string('runtime', 32)->default('docker');
            $table->string('runtime_ref', 191)->nullable();

            // Which dply machine holds the container. Null while the runtime
            // places workers on the control plane itself.
            $table->foreignUlid('host_server_id')->nullable()->index();

            $table->string('state', 24)->default('starting');
            $table->string('stop_reason', 64)->nullable();

            // Sized at start and kept, because the fleet's size may be edited
            // while this worker is still running and still billed at the old
            // size until it is replaced.
            $table->unsignedInteger('memory_mib')->default(256);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Accrued on stop. Nullable rather than 0 so "never settled" and
            // "ran for no measurable time" stay distinguishable.
            $table->unsignedBigInteger('billed_seconds')->nullable();
            $table->timestamp('billed_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // The autoscaler's hot read: live workers for one fleet.
            $table->index(['fleet_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dply_queue_workers');
    }
};
