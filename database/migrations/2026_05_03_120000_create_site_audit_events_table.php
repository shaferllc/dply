<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-site audit log of mutating actions, regardless of transport
     * (web UI, CLI, scaffolded-system action). Every destructive command
     * writes a row here; every applied scaffold-default writes a row;
     * every snapshot taken or restored writes a row.
     *
     * Read paths don't audit by default — that would explode row counts
     * with no investigative value. The audit table is for "who did what
     * irrecoverable thing to this site, and when."
     */
    public function up(): void
    {
        Schema::create('site_audit_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignUlid('site_id')
                ->references('id')->on('sites')
                ->cascadeOnDelete();

            // Nullable for system-triggered actions (e.g. scaffold pipeline
            // applying hardening defaults — no human "did" the action).
            $table->foreignUlid('user_id')
                ->nullable()
                ->references('id')->on('users')
                ->nullOnDelete();

            // Action verb, e.g. 'wp_cli_run', 'artisan_run',
            // 'snapshot_taken', 'snapshot_restored', 'plugin_installed',
            // 'plugin_deleted', 'hardening_applied', 'hardening_reverted',
            // 'scaffold_default_applied', 'migration_rolled_back',
            // 'site_url_changed', 'salts_regenerated'.
            $table->string('action', 64);

            // 'read' | 'mutating_recoverable' | 'destructive'.
            // Read events shouldn't normally land here; if they do, they're
            // because they were elevated (e.g. tinker — runtime input, can't
            // statically classify, treated as destructive).
            $table->string('risk', 32);

            // 'web' (Livewire UI) | 'cli' (dply:* command) | 'system' (pipeline).
            $table->string('transport', 16);

            // Human one-liner for the event log row. Always present.
            $table->string('summary', 500);

            // Structured details — command + args for CLI runs, plugin
            // slug + version for plugin actions, snapshot id for restores,
            // diff for hardening flips. Schema is action-specific.
            $table->json('payload')->nullable();

            // 'success' | 'failure' — captured once the action terminates.
            // Pending events (mid-flight) are not audit material — only
            // settled ones are recorded.
            $table->string('result_status', 16);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['site_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_audit_events');
    }
};
