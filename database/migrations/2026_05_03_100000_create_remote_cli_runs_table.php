<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent log of every WordPress wp-cli and Laravel artisan
     * invocation ran against a Site, regardless of transport (web UI
     * console, queue worker, dply:wp / dply:artisan CLI).
     *
     * Unifies WP + Artisan because the schema is identical and the
     * services share a base (RemoteCli). The `kind` discriminator
     * lets us filter "show me this site's recent wp-cli activity"
     * without joining a separate table.
     */
    public function up(): void
    {
        Schema::create('remote_cli_runs', function (Blueprint $table): void {
            $table->id();

            $table->foreignUlid('site_id')
                ->references('id')->on('sites')
                ->cascadeOnDelete();

            // 'wp' (wp-cli) | 'artisan' (php artisan)
            $table->string('kind', 16);

            // The first wp/artisan word + subcommand, e.g. "plugin install"
            // or "migrate:rollback". Stored for searchability and risk
            // classification; the full args list lives in `args`.
            $table->string('command', 200);

            // Full arg list as a JSON array (e.g. ["woocommerce", "--activate"])
            // so the run can be replayed verbatim.
            $table->json('args')->nullable();

            // 'read' | 'mutating_recoverable' | 'destructive'
            // Set by the service before dispatch, immutable thereafter.
            $table->string('risk', 32);

            // 'sync' (returned inline within the originating HTTP request)
            // | 'async' (dispatched to the queue, output streamed via tail()).
            $table->string('mode', 16);

            // 'queued' (async, not yet picked up)
            // | 'running' (in flight)
            // | 'completed' (exit_code captured, may be 0 or non-zero)
            // | 'failed' (process did not return a clean exit — timeout, ssh, etc.)
            // | 'cancelled' (operator clicked cancel before completion).
            $table->string('status', 16);

            // Process exit code, captured once status leaves 'running'.
            $table->smallInteger('exit_code')->nullable();

            // Captured stdout/stderr. Inline-stored for v1; if any single
            // run exceeds a few MB we'll split to a chunks table later.
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();

            // Who initiated the run. Nullable for system-triggered runs
            // (e.g. scaffold pipeline applying hardening defaults).
            $table->foreignUlid('queued_by_user_id')
                ->nullable()
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // "Recent runs for this site" — the dominant read pattern
            // for the Console sub-tab + history pane. created_at for
            // recency sort; (site_id, kind) covers per-tab filters.
            $table->index(['site_id', 'created_at']);
            $table->index(['site_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_cli_runs');
    }
};
