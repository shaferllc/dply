<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the operator-set AUTH password for redis-family cache engines
 * (redis / valkey / keydb / dragonfly). Encrypted at rest via Laravel's
 * `encrypted` cast on the model. Memcached has no native auth so this
 * column is unused for that engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->text('auth_password')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropColumn('auth_password');
        });
    }
};
