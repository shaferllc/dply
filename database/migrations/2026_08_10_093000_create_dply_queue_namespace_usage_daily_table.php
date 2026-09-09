<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily jobs-pushed rollup PER NAMESPACE.
 *
 * Deliberately not `dply_queue_usage_daily`. A table by that name already
 * exists, keyed by `organization_id` + `day` with `source`/`meta` columns —
 * the org-scoped shape the metered-billing design in docs/adr/dply-queue.md
 * decision 9 called for. That design was superseded (pricing is now
 * per-namespace by capacity tier), and this series is a different thing: one
 * row per namespace per day, feeding a per-queue sparkline that an org-scoped
 * rollup cannot produce. Two shapes, two purposes, two names.
 *
 * On the PRIMARY connection, unlike the job and failed-job tables. Those hold
 * customer payloads and churn hard, which is why they live on `dply_queue`;
 * this is a small append-mostly counter the control plane reads to draw a
 * sparkline, and keeping it beside namespaces means the dashboard does not need
 * the data-plane database to be reachable to render.
 *
 * OBSERVATIONAL. Nothing is invoiced from these numbers — a namespace is priced
 * by its capacity tier, so a lost flush costs a few pixels of chart, not money.
 * That is a deliberate downgrade from what docs/adr/dply-queue.md decision 9
 * originally specified; see docs/adr/managed-services-tier.md, decision 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dply_queue_namespace_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->ulid('namespace_id');
            $table->date('usage_date');
            $table->unsignedBigInteger('jobs_pushed')->default(0);
            $table->timestampsTz();

            // The flush upserts on this pair, so it must be unique.
            $table->unique(['namespace_id', 'usage_date'], 'dqnud_namespace_date_unique');
            $table->index('usage_date', 'dqnud_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dply_queue_namespace_usage_daily');
    }
};
