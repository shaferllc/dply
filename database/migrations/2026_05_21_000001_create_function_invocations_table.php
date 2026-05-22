<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified log of every invocation of a serverless (DigitalOcean Functions)
 * site. The DO activations list API is structurally empty, so dply records
 * an invocation row from one of two real sources:
 *
 *  - `tick` / `test` — invocations dply itself makes via the authenticated
 *    blocking action API, which returns the full activation inline.
 *  - `web` — organic HTTP traffic, POSTed to dply's ingest endpoint by the
 *    deployed function handler after each request.
 *
 * This table supersedes the `meta.serverless.tick_history` ring buffer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('function_invocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            // tick | test | web
            $table->string('source', 16);
            // schedule | queue | keep-warm | null (for web / test)
            $table->string('task', 16)->nullable();
            $table->string('method', 12)->nullable();
            $table->string('path', 2048)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('cold')->default(false);
            $table->string('activation_id', 64)->nullable();
            // Captured stdout/stderr (tick/test) or Laravel log records (web).
            $table->json('log_lines')->nullable();
            $table->text('result_excerpt')->nullable();
            $table->timestamp('created_at')->nullable();

            // The Logs page filters by site + source, newest-first; the prune
            // command sweeps by site + source + age.
            $table->index(['site_id', 'source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('function_invocations');
    }
};
