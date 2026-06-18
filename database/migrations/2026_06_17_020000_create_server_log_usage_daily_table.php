<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * dply Logs billing — Phase 1 "the gate", PR A (metering).
 *
 * A per-org daily rollup of ingest volume, derived from the ClickHouse log store
 * (the cost driver: ClickHouse insert + retention). Read-only and customer-invisible
 * for now — it just lets us SEE GB/day before pricing anything. The billable number
 * comes from this table, not from Vector internal metrics, so it survives aggregator
 * restarts and never double-counts. See docs/SERVER_LOGS_BILLING.md §1.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_log_usage_daily', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('events')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('source', 32)->default('clickhouse');
            $table->json('meta')->nullable();
            $table->timestamps();

            // Idempotent upsert key: one row per org per day per source, so the
            // hourly re-meter of the current day and the nightly finalize of the
            // prior day overwrite in place rather than accumulating.
            $table->unique(['organization_id', 'day', 'source']);
            $table->index(['organization_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_log_usage_daily');
    }
};
