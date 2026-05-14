<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per site under an import_server_migrations parent. Per-site abort
 * is the right scope (per Q13) — a failing migration of site B doesn't
 * abort site A. SSL strategy auto-selected per Q9b lives on this row.
 *
 * `source_snapshot` is the frozen-at-start picture of the Ploi site (env,
 * crons, daemons, DB info, repository state). Once written, the migration
 * orchestrator never re-reads from Ploi — only from this row. Lazy inventory
 * refreshes can mutate the source PloiSite without affecting in-flight runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_site_migrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('import_server_migration_id')->constrained('import_server_migrations')->cascadeOnDelete();
            $table->string('source', 32);
            $table->unsignedBigInteger('source_site_id');
            $table->foreignUlid('target_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('domain', 255);
            $table->string('site_type', 32);
            // Lifecycle: pending → staging → ready_for_cutover → cutover_in_progress →
            // completed | aborted | cutover_failed | cutover_rolled_back.
            $table->string('status', 32)->default('pending');
            // 'clean' (DNS-01), 'bridged' (cert-copy), 'gap' (HTTP-01 post-cutover).
            $table->string('ssl_strategy', 16)->nullable();
            $table->json('source_snapshot');
            $table->timestamp('staging_completed_at')->nullable();
            $table->timestamp('cutover_started_at')->nullable();
            $table->timestamp('cutover_completed_at')->nullable();
            $table->text('failure_summary')->nullable();
            $table->timestamps();

            $table->index(['import_server_migration_id', 'status'], 'import_site_migrations_parent_status_idx');
            $table->index(['source', 'source_site_id'], 'import_site_migrations_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_site_migrations');
    }
};
