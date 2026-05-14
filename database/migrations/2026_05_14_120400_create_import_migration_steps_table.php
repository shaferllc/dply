<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Declared-upfront steps for a migration run. Each step attaches to either
 * the parent (server-level: push_ssh_key, eligibility_scan, revoke_ssh_key)
 * or a child site (per-site: clone_repo, copy_env, dump_db, restore_db,
 * recreate_crons, recreate_daemons, recreate_scheduler, setup_ssl, cutover,
 * webhook_swap, smoke_test).
 *
 * Per Q7 the plan is declared at confirm time, so the UI can show all steps
 * with status pills from t=0 — no surprise step list growth. The orchestrator
 * marks each step pending → running → succeeded | failed | skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_migration_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('import_server_migration_id')->constrained('import_server_migrations')->cascadeOnDelete();
            // Null when the step is server-scoped (push_ssh_key, revoke_ssh_key, etc.).
            $table->foreignUlid('import_site_migration_id')->nullable()->constrained('import_site_migrations')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('step_key', 64);
            // 'pending' | 'running' | 'succeeded' | 'failed' | 'skipped'.
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->string('log_object_key', 500)->nullable();
            $table->json('result_data')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['import_server_migration_id', 'sequence'], 'import_migration_steps_seq_idx');
            $table->index(['import_site_migration_id', 'sequence'], 'import_migration_steps_site_seq_idx');
            $table->index(['import_server_migration_id', 'status'], 'import_migration_steps_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_migration_steps');
    }
};
