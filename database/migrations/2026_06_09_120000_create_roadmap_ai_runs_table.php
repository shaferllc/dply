<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit + cursor for the post-deploy AI roadmap updater. Each row records one
 * run: the git range it considered, what it changed, the token spend, and the
 * raw plan it applied. The most recent completed row's `to_commit` is the
 * cursor the next run diffs forward from, so a deploy only ever reasons about
 * commits it hasn't seen before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_ai_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // 'running' | 'completed' | 'failed' | 'skipped'
            $table->string('status', 16)->default('running');

            // Git range reasoned about (nullable on the very first run / no git).
            $table->string('from_commit', 64)->nullable();
            $table->string('to_commit', 64)->nullable();

            $table->unsignedInteger('commits_considered')->default(0);
            $table->unsignedInteger('items_shipped')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('suggestions_triaged')->default(0);
            $table->unsignedInteger('summaries_updated')->default(0);

            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // Why a run skipped (disabled, llm not configured, no new commits)
            // or the error message when it failed.
            $table->text('note')->nullable();

            // The full applied plan as returned by the model, for audit.
            $table->json('plan')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_ai_runs');
    }
};
