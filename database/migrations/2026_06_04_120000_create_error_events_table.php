<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only stream of error events, surfaced as a dedicated "Errors" view in
 * the site and server workspaces. Rows are written by listeners on failed
 * ConsoleActions and SiteDeployments (see ErrorEventRecorder); each carries a
 * denormalized server_id/site_id so the per-entity views are a single cheap
 * indexed read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('organization_id')->nullable()->index();
            // The box this error is about (server view = everything on the box,
            // including hosted sites). Null only when it can't be resolved.
            $table->string('server_id')->nullable();
            // The site that owns the error, when it's site-scoped (deploy, ssl,
            // binding). Null for pure-infra errors.
            $table->string('site_id')->nullable();

            // Polymorphic pointer back to the origin (ConsoleAction / SiteDeployment).
            $table->string('source_type');
            $table->string('source_id');

            // Drives the retry registry + grouping. For ConsoleActions this is
            // the kind ('db_engine_install', 'binding_connectivity_fix', …);
            // for deployments it's 'deploy'.
            $table->string('category')->index();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('link_url')->nullable();

            $table->timestamp('occurred_at')->index();
            $table->timestamp('dismissed_at')->nullable();
            $table->string('dismissed_by')->nullable();

            $table->timestamps();

            // One error per source row — makes capture idempotent (a listener
            // firing twice, or the backfill re-running, updates in place).
            $table->unique(['source_type', 'source_id']);

            // The two workspace views' primary queries.
            $table->index(['server_id', 'occurred_at']);
            $table->index(['site_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
