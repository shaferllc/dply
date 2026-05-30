<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_database_backups', function (Blueprint $table) {
            $table->string('storage_kind', 32)->default('remote_server')->after('status');
            $table->string('remote_path')->nullable()->after('disk_path');
            $table->foreignUlid('backup_configuration_id')
                ->nullable()
                ->after('user_id')
                ->constrained('backup_configurations')
                ->nullOnDelete();
            $table->string('s3_bucket')->nullable()->after('remote_path');
            $table->string('s3_key')->nullable()->after('s3_bucket');
        });

        DB::table('server_database_backups')
            ->whereNotNull('disk_path')
            ->where('disk_path', '!=', '')
            ->update(['storage_kind' => 'control_plane']);
    }

    public function down(): void
    {
        Schema::table('server_database_backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backup_configuration_id');
            $table->dropColumn(['storage_kind', 'remote_path', 's3_bucket', 's3_key']);
        });
    }
};
