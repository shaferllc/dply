<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-server audit log for webserver-switch operations. Mirrors the richer
     * shape used by {@see \App\Models\SiteAuditEvent} (action/risk/transport/
     * summary/payload/result_status) rather than the older event+meta pattern
     * used by ServerFirewallAuditEvent — the newer shape captures the data the
     * switch flow actually wants to record (from/to, opt-in cascades, sites
     * affected, computed duration).
     */
    public function up(): void
    {
        Schema::create('server_webserver_audit_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignUlid('server_id')
                ->references('id')->on('servers')
                ->cascadeOnDelete();

            // Nullable for system-triggered actions (scheduled jobs, etc.).
            $table->foreignUlid('user_id')
                ->nullable()
                ->references('id')->on('users')
                ->nullOnDelete();

            // Action verb, e.g. 'server_webserver_switched',
            // 'server_webserver_switch_failed', 'server_webserver_rollback'.
            $table->string('action', 64);

            // 'read' | 'mutating_recoverable' | 'destructive'. Switches are
            // mutating_recoverable until cutover, destructive after.
            $table->string('risk', 32);

            // 'web' (Livewire UI) | 'cli' (dply:* command) | 'system' (job).
            $table->string('transport', 16);

            // Human one-liner. Always present.
            $table->string('summary', 500);

            // Structured details — from/to webserver, cascade opt-ins,
            // affected_site_ids, duration_ms, validation_results.
            $table->json('payload')->nullable();

            // 'success' | 'failure' — set once the action terminates.
            $table->string('result_status', 16);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['server_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['server_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_webserver_audit_events');
    }
};
