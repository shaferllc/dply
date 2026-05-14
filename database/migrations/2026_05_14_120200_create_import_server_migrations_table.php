<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parent row per "Migrate this Ploi server" action. Tracks the link between
 * a PloiServer (source) and a dply Server (target), the ephemeral SSH key
 * fingerprint for the trust-window, and the overall lifecycle.
 *
 * `source` is a discriminator so a future Forge driver lands here without a
 * second table — same shape, different source value, same step machinery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_server_migrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users');
            $table->foreignUlid('provider_credential_id')->constrained('provider_credentials');
            $table->string('source', 32); // 'ploi' (v1), 'forge' (next)
            $table->unsignedBigInteger('source_server_id');
            $table->foreignUlid('target_server_id')->nullable()->constrained('servers')->nullOnDelete();
            // Lifecycle: pending → server_provisioning → staging → ready_for_cutover →
            // cutover_in_progress → completed | partial | aborted | cutover_failed.
            $table->string('status', 32)->default('pending');
            // Ephemeral key fingerprint; full keypair lives encrypted on the run row.
            $table->string('ssh_key_fingerprint', 100)->nullable();
            $table->text('ssh_key_public')->nullable();
            $table->text('ssh_key_private_encrypted')->nullable();
            $table->unsignedInteger('ssh_key_source_id')->nullable(); // returned by Ploi API
            $table->timestamp('ssh_key_pushed_at')->nullable();
            $table->timestamp('ssh_key_revoked_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_summary')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'import_server_migrations_org_status_idx');
            $table->index(['source', 'source_server_id'], 'import_server_migrations_source_idx');
            $table->index('target_server_id', 'import_server_migrations_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_server_migrations');
    }
};
