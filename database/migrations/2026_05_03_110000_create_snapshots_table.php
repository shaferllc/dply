<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database snapshots for a Site, taken either manually from the
     * Database sub-tab or automatically before destructive operations
     * (migrate:rollback, wp db drop, search-replace --all-tables, ...).
     *
     * Two destinations are supported:
     *  - 'local_disk': transient safety-net; hard TTL via expires_at,
     *    swept by a scheduled job. Not user-facing as a "backup."
     *  - 's3': BYO S3-compatible bucket via an org's provider credential
     *    (DO Spaces, B2, R2, S3). The user-facing archive product.
     */
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table): void {
            $table->id();

            $table->foreignUlid('site_id')
                ->references('id')->on('sites')
                ->cascadeOnDelete();

            // 'local_disk' | 's3'
            $table->string('destination', 16);

            // Populated when destination = 's3'. The bucket name is the
            // S3-compatible bucket the org configured; key is the object
            // path within it.
            $table->string('s3_bucket', 200)->nullable();
            $table->string('s3_key', 500)->nullable();

            // Populated when destination = 'local_disk'. Absolute path on
            // the server's filesystem.
            $table->string('local_path', 500)->nullable();

            // Compressed dump size in bytes — surfaced in the UI for
            // restore time estimates and disk-usage reporting.
            $table->unsignedBigInteger('bytes')->nullable();

            // Engine used to dump: 'mysql' | 'mariadb' | 'postgres' | 'sqlite'.
            // Restore must use the same; mismatch is a hard error.
            $table->string('engine', 16);

            // 'manual' | 'pre_migration_rollback' | 'pre_destructive_command' | 'scheduled'
            // Drives expires_at default for local_disk, and surfaces
            // why a snapshot exists in the audit log.
            $table->string('reason', 32);

            $table->foreignUlid('taken_by_user_id')
                ->nullable()
                ->references('id')->on('users')
                ->nullOnDelete();

            // Local-disk transient snapshots get a 7-day TTL by default;
            // S3 snapshots leave this null (S3 lifecycle rules own retention).
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            // Sweeper index — single-column, since the sweeper scans
            // global "everything that has expired".
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
