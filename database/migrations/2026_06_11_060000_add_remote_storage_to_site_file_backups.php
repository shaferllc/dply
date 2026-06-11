<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_file_backups', function (Blueprint $table): void {
            // Where the durable archive lives. New backups write the tar to the
            // SITE'S server (storage_kind=remote_server) so it's reachable over
            // SSH from any control-plane box. disk_path stays for legacy rows
            // (storage_kind null => treated as control_plane).
            $table->string('remote_path', 512)->nullable()->after('disk_path');
            $table->string('storage_kind')->nullable()->after('remote_path');
        });
    }

    public function down(): void
    {
        Schema::table('site_file_backups', function (Blueprint $table): void {
            $table->dropColumn(['remote_path', 'storage_kind']);
        });
    }
};
