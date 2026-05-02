<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'dply_auth_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropUnique('users_dply_auth_id_unique');
            } catch (\Throwable) {
                // Index may already be absent on dialects that drop indexes with the column.
            }
            $table->dropColumn('dply_auth_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'dply_auth_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('dply_auth_id')->nullable()->unique();
        });
    }
};
