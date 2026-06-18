<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_download_stagings', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The backup being staged: ServerDatabaseBackup or SiteFileBackup.
            $table->ulidMorphs('backupable');

            $table->char('requested_by_user_id', 26)->nullable();

            // pending -> ready | failed
            $table->string('status')->default('pending');

            // hetzner = a temporary copy lives in the staging bucket (delete on
            // expiry); direct = the durable artifact is already a presignable S3
            // object (org destination), nothing to copy or delete.
            $table->string('mode')->default('hetzner');

            $table->string('bucket')->nullable();
            $table->string('object_key', 512)->nullable();

            $table->text('error_message')->nullable();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['backupable_type', 'backupable_id', 'status']);

            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_download_stagings');
    }
};
