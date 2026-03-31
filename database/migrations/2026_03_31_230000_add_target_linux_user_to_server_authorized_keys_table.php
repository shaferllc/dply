<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->dropUnique('srv_auth_keys_managed_unique');
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->string('target_linux_user', 64)->default('');
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->unique(
                ['server_id', 'managed_key_type', 'managed_key_id', 'target_linux_user'],
                'srv_auth_keys_managed_target_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->dropUnique('srv_auth_keys_managed_target_unique');
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->dropColumn('target_linux_user');
        });

        Schema::table('server_authorized_keys', function (Blueprint $table) {
            $table->unique(['server_id', 'managed_key_type', 'managed_key_id'], 'srv_auth_keys_managed_unique');
        });
    }
};
