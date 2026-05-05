<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per provision step per run. Powers the "Avg X minutes
     * (from N previous runs)" ETA in the provision-journey UI by giving
     * us a per-(label_hash, organization) average instead of the static
     * "Usually a few minutes" copy.
     *
     * label_hash matches ProvisionStepSnapshots::keyForLabel() so step
     * snapshot UI keys and ETA lookups address the same row identity.
     */
    public function up(): void
    {
        Schema::create('server_provision_step_runs', function (Blueprint $table): void {
            // ULID primary key — matches the rest of the
            // server_provision_* family (HasUlids on the model would
            // otherwise tear against an auto-increment id column).
            $table->ulid('id')->primary();

            $table->foreignUlid('server_id')
                ->references('id')->on('servers')
                ->cascadeOnDelete();

            // Org-scoped averages keep a Node-heavy fleet's ETAs out of a
            // PHP-only fleet's UI. Indexed for the avg lookup.
            $table->foreignUlid('organization_id')
                ->references('id')->on('organizations')
                ->cascadeOnDelete();

            // Both relations are nullable: the Run / Task rows can be
            // garbage-collected by the journey while we still want the
            // historical step duration to live on for averaging.
            $table->foreignUlid('server_provision_run_id')
                ->nullable()
                ->references('id')->on('server_provision_runs')
                ->nullOnDelete();

            $table->foreignUlid('task_id')
                ->nullable()
                ->references('id')->on('task_runner_tasks')
                ->nullOnDelete();

            // ProvisionStepSnapshots::keyForLabel() returns 'script_'.md5(label) → 23 chars.
            // Char(40) leaves headroom for any future hash widening.
            $table->string('label_hash', 40)->index();
            $table->string('label');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);

            // Resume-skip steps land with resumed=true; the ETA service
            // excludes them so a 0-second skip can't drag the running
            // mean down toward zero on subsequent runs.
            $table->boolean('resumed')->default(false);

            $table->timestamps();

            // Avg / count lookups are always (label_hash, organization_id)
            // with completed_at IS NOT NULL; the composite index covers it.
            $table->index(['label_hash', 'organization_id', 'completed_at'], 'spsr_avg_lookup_idx');

            // Per-server timeline view (Server workspace → "How long did
            // each step take on THIS server").
            $table->index(['server_id', 'completed_at'], 'spsr_server_timeline_idx');

            // Idempotency: a single task should never produce the same
            // step twice. Lets the recorder use insertOrIgnore() / upsert
            // safely on retried task observations.
            $table->unique(['task_id', 'label_hash'], 'spsr_task_label_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_provision_step_runs');
    }
};
