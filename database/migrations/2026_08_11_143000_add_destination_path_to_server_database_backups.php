<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a dump landed on a non-S3 destination (SFTP / FTP / Rclone).
 *
 * `s3_bucket` + `s3_key` already record the location for S3-compatible
 * destinations. Reusing them for an SFTP path would make every future reader
 * guess which meaning applies, so non-S3 destinations get their own column and
 * `storage_kind` stays the discriminator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_database_backups', function (Blueprint $table): void {
            // Long enough for a deep base path plus a dated key prefix.
            $table->string('destination_path', 1024)->nullable()->after('s3_key');
        });
    }

    public function down(): void
    {
        Schema::table('server_database_backups', function (Blueprint $table): void {
            $table->dropColumn('destination_path');
        });
    }
};
