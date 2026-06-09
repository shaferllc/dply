<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_database_admin_credentials', function (Blueprint $table): void {
            $table->string('mongodb_admin_username')->nullable()->after('postgres_use_sudo');
            $table->text('mongodb_admin_password')->nullable()->after('mongodb_admin_username');
            $table->string('clickhouse_admin_username')->nullable()->after('mongodb_admin_password');
            $table->text('clickhouse_admin_password')->nullable()->after('clickhouse_admin_username');
        });
    }

    public function down(): void
    {
        Schema::table('server_database_admin_credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'mongodb_admin_username',
                'mongodb_admin_password',
                'clickhouse_admin_username',
                'clickhouse_admin_password',
            ]);
        });
    }
};
