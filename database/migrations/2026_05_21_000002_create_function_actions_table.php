<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One OpenWhisk action belonging to a serverless function-Site.
 *
 * dply is moving from "1 Site = 1 action" to a package model where a Site
 * (an OpenWhisk package) holds N actions: each deployable function is a
 * `kind=code` row; a composition is a `kind=sequence` row. Per-action
 * settings — runtime, resource limits, the invocation URL, the scheduled
 * trigger, and (for sequences) the ordered component list — live here
 * rather than smeared across `Site.meta.serverless`.
 *
 * This migration only creates the table. Existing serverless Sites are
 * backfilled (one `kind=code` row each) by the idempotent
 * `serverless:backfill-function-actions` command — so no behaviour changes
 * until that command runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('function_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            // The OpenWhisk action name within the Site's namespace/package.
            $table->string('name');
            // code | sequence
            $table->string('kind', 16)->default('code');
            // Runtime kind string — e.g. nodejs:18, php:8.3, python:3.11,
            // go:1.22. Empty for kind=sequence (a sequence runs no code).
            $table->string('runtime')->default('');
            // OpenWhisk exec.main — the handler symbol or file.
            $table->string('entrypoint')->default('');
            $table->unsignedSmallInteger('memory_mb')->nullable();
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->unsignedSmallInteger('concurrency')->nullable();
            // Deployed invocation URL, once the action has been pushed.
            $table->string('url', 2048)->nullable();
            // Scheduled-trigger (cron) config — populated when real DO
            // triggers land; null means no schedule.
            $table->json('trigger')->nullable();
            // Ordered component action references for kind=sequence.
            $table->json('components')->nullable();
            // Catch-all for per-action detail not yet promoted to a column.
            $table->json('meta')->nullable();
            $table->timestamps();

            // An action name is unique within its Site (its OpenWhisk package).
            $table->unique(['site_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('function_actions');
    }
};
