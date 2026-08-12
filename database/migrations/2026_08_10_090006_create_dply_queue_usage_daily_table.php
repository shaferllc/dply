<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org daily rollup of dply Queue jobs pushed — the billable number
 * (docs/adr/dply-queue.md, decision 9).
 *
 * Metered on jobs PUSHED, not API requests. Billing per request would charge
 * the customer for dply's own polling design and reward us for never
 * improving it; jobs pushed is the unit the customer actually recognises.
 *
 * Unlike dply Logs, this cannot be re-derived from the store after the fact:
 * an acked job row is deleted, so by the time any nightly pass ran the
 * evidence would be gone. The count is therefore accumulated at push time in
 * Redis and flushed here — see QueueUsageMeter.
 *
 * Rolled up per ORGANIZATION, not per namespace. Billing is org-level, and a
 * namespace can be deleted while the month it was billed for cannot.
 *
 * Lives in the primary database with namespaces and credentials; only job
 * rows go to the `dply_queue` connection (decision 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dply_queue_usage_daily', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->date('day');

            $table->unsignedBigInteger('jobs_pushed')->default(0);

            $table->string('source', 32)->default('counter');
            $table->json('meta')->nullable();
            $table->timestamps();

            // Idempotent upsert key: one row per org per day per source. The
            // hourly flush rewrites the current day in place — it writes the
            // counter's running total, not a delta, so a flush that overlaps
            // a push can never double-count or lose one.
            $table->unique(['organization_id', 'day', 'source']);
            $table->index(['organization_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dply_queue_usage_daily');
    }
};
