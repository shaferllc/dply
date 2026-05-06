<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the one-instance-per-engine invariant. With the `name` column in place
 * (added in 2026_05_05_235000), each (server_id, engine) can now have multiple
 * rows distinguished by name + port. The two new unique indexes:
 *
 *   - (server_id, port)         → two services on the same server can't bind
 *                                 to the same port. Stronger than (engine, port)
 *                                 since 'redis on 6379' and 'memcached on 6379'
 *                                 would still collide at the kernel.
 *   - (server_id, engine, name) → human-stable identifier per engine. Lets
 *                                 'redis-primary' and 'valkey-primary' coexist
 *                                 on one server while preventing duplicate
 *                                 names within an engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Belt and suspenders: in the unlikely event that any (server_id, port)
        // collisions exist (shouldn't with the prior unique constraint, but
        // defensive against seeded data or a hand-edited row), bail loudly so
        // the operator sees it before the new index would error.
        $portDupes = DB::table('server_cache_services')
            ->select('server_id', 'port', DB::raw('count(*) as c'))
            ->groupBy('server_id', 'port')
            ->havingRaw('count(*) > 1')
            ->get();
        if ($portDupes->isNotEmpty()) {
            throw new RuntimeException('Cannot apply migration: existing duplicate (server_id, port) rows in server_cache_services. Resolve manually before running this migration.');
        }

        $nameDupes = DB::table('server_cache_services')
            ->select('server_id', 'engine', 'name', DB::raw('count(*) as c'))
            ->groupBy('server_id', 'engine', 'name')
            ->havingRaw('count(*) > 1')
            ->get();
        if ($nameDupes->isNotEmpty()) {
            throw new RuntimeException('Cannot apply migration: existing duplicate (server_id, engine, name) rows in server_cache_services. Resolve manually before running this migration.');
        }

        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine']);
            $table->unique(['server_id', 'port']);
            $table->unique(['server_id', 'engine', 'name']);
        });
    }

    public function down(): void
    {
        // Reversing only works if each (server_id, engine) has at most one row
        // again. If a server has two redis instances, the operator must decide
        // which to keep before rolling back; we don't silently delete data.
        $multi = DB::table('server_cache_services')
            ->select('server_id', 'engine', DB::raw('count(*) as c'))
            ->groupBy('server_id', 'engine')
            ->havingRaw('count(*) > 1')
            ->get();
        if ($multi->isNotEmpty()) {
            throw new RuntimeException('Cannot rollback: some (server_id, engine) pairs have multiple cache_services rows. Pick one per pair to keep, delete the others, then retry.');
        }

        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine', 'name']);
            $table->dropUnique(['server_id', 'port']);
            $table->unique(['server_id', 'engine']);
        });
    }
};
