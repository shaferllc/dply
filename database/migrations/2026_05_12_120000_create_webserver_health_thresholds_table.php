<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org and per-server overrides for the webserver / edge-proxy health
 * alert thresholds. Hardcoded fallbacks live in config/server_metrics.php;
 * this table lets operators tune them without editing config.
 *
 * Resolution precedence (most specific wins):
 *   1. (server_id, engine, metric) — fire-and-forget per-engine on this box
 *   2. (server_id, NULL,   metric) — server-wide override (all engines)
 *   3. (NULL,      engine, metric) → not currently used; reserved
 *   4. (organization_id, engine, metric) — org default for this engine
 *   5. (organization_id, NULL,   metric) — org default, all engines
 *   6. config/server_metrics.php fallback
 *
 * server_id and organization_id are mutually exclusive on a given row
 * (either it's a server-level override or an org-level default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webserver_health_thresholds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // ULID FKs — server.id and organization.id are both ULIDs in dply.
            $table->ulid('organization_id')->nullable();
            $table->ulid('server_id')->nullable();
            // Null engine = applies to ALL engines on the scope (server/org).
            // Non-null = scoped to a specific engine key ('nginx', 'caddy', etc.).
            $table->string('engine', 32)->nullable();
            // Metric key matching keys in config/server_metrics.php.health_thresholds.
            $table->string('metric', 64);
            $table->enum('comparator', ['gt', 'gte', 'lt', 'lte']);
            $table->double('value');
            // 'warning' or 'critical'. Drives the notification severity copy.
            $table->enum('severity', ['warning', 'critical'])->default('warning');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->index(['server_id', 'engine', 'metric'], 'whth_server_engine_metric_idx');
            $table->index(['organization_id', 'engine', 'metric'], 'whth_org_engine_metric_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webserver_health_thresholds');
    }
};
