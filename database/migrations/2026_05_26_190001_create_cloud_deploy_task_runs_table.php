<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per execution of a cloud_deploy_task. Populated by a sync
 * pass after each DO deploy completes (for pre/post/failed triggers,
 * the deployment_id ties back to the DO deploy), and inline when an
 * operator hits "Run now" on a MANUAL task.
 *
 * Persisting these lets the dashboard show per-run outcomes ("migrate
 * succeeded 2m ago, exit 0, 4.1s") without spelunking DO's API each
 * time the page renders.
 *
 * The trigger is snapshotted into this row so historic runs survive
 * later config changes — if the user renames or re-purposes a task,
 * past runs still describe what actually ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_deploy_task_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cloud_deploy_task_id')->index();
            // DO deployment id (the rollout this run belongs to). Null
            // for MANUAL runs that aren't tied to a deploy.
            $table->string('deployment_id', 64)->nullable()->index();
            // Snapshot of the task's trigger at run time.
            $table->string('trigger', 24);
            // running | succeeded | failed | canceled
            $table->string('status', 32)->default('running');
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // Last ~N lines of the task's stderr/stdout from DO. Optional;
            // populated lazily after the sync pass.
            $table->text('log_tail')->nullable();
            // Free-form error string when dply itself couldn't start the
            // run (e.g. DO returned 5xx). Distinct from log_tail, which
            // is what the task itself printed.
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_deploy_task_runs');
    }
};
