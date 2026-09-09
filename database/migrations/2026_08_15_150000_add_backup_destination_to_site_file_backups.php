<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a site's file archive ship to a backup destination instead of only
 * living on the site's own server.
 *
 * Database backups have had this since destinations shipped
 * (`server_database_backups.backup_configuration_id` + `destination_path`), but
 * site files never got the columns, so the exporter had nowhere to record a
 * destination and every archive stayed on the box it was made on — the one
 * place a backup is no use when that box is what you lost.
 *
 * Mirrors the database side deliberately: `storage_kind` stays the
 * discriminator, `destination_path` holds the provider-side handle (a POSIX
 * path for SFTP/FTP/Rclone/Dropbox, an opaque file id for Google Drive), and
 * the existing `remote_path` / `disk_path` keep their current meanings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_file_backups', function (Blueprint $table): void {
            // nullOnDelete, not cascade: losing the destination record must not
            // delete the history of backups that were sent to it.
            $table->foreignUlid('backup_configuration_id')
                ->nullable()
                ->after('storage_kind')
                ->constrained('backup_configurations')
                ->nullOnDelete();

            // Long enough for a deep base path plus a dated key prefix.
            $table->string('destination_path', 1024)->nullable()->after('remote_path');
        });
    }

    public function down(): void
    {
        Schema::table('site_file_backups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('backup_configuration_id');
            $table->dropColumn('destination_path');
        });
    }
};
