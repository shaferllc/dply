<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a partial unique index on `(server_id, internal_port)` to enforce
 * port uniqueness per server at the DB level.
 *
 * Without this, the allocator's optimistic assignment could race with a
 * concurrent site creation and produce two sites pointed at the same
 * upstream port — silently corrupting NGINX routing.
 *
 * The index is partial (`WHERE internal_port IS NOT NULL`) so that PHP
 * and static sites — which always have NULL internal_port — don't all
 * collide on a single null bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres supports partial indexes; SQLite does too. MySQL does
        // not, but this app's primary deploy target is postgres and the
        // index is a defense-in-depth measure on top of the allocator.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX sites_server_id_internal_port_unique '.
                'ON sites (server_id, internal_port) '.
                'WHERE internal_port IS NOT NULL'
            );

            return;
        }

        // MySQL fallback: a regular unique index. Multiple NULLs collide
        // here too — but MySQL treats NULL as distinct in unique indexes,
        // so this still works.
        Schema::table('sites', function (Blueprint $table) {
            $table->unique(['server_id', 'internal_port'], 'sites_server_id_internal_port_unique');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS sites_server_id_internal_port_unique');

            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique('sites_server_id_internal_port_unique');
        });
    }
};
