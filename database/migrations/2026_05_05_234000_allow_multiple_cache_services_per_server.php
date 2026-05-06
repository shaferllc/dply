<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the one-cache-per-server invariant. Each server can now run one row per (engine), so
 * Redis + Memcached side by side becomes legal: Redis covers queues/Horizon/locks, Memcached
 * covers app cache without paying Redis's per-key overhead.
 *
 * The original migration created `unique(server_id)`; we drop that and replace it with a
 * composite `unique(server_id, engine)` so we still reject duplicates of the same engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Belt and suspenders: if any (server_id, engine) collisions slipped in (shouldn't with
        // the old single-row constraint, but defensive in case of seed data), bail loudly so the
        // operator sees it before the new unique index would error.
        $dupes = DB::table('server_cache_services')
            ->select('server_id', 'engine', DB::raw('count(*) as c'))
            ->groupBy('server_id', 'engine')
            ->havingRaw('count(*) > 1')
            ->get();
        if ($dupes->isNotEmpty()) {
            throw new RuntimeException('Cannot apply migration: existing duplicate (server_id, engine) rows in server_cache_services. Resolve manually before running this migration.');
        }

        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropUnique(['server_id']);
            $table->unique(['server_id', 'engine']);
        });
    }

    public function down(): void
    {
        // Reversing only works if each server has at most one row again. If multiple engines are
        // installed per server, the operator must pick one to keep before rolling back; we don't
        // silently delete data.
        $multi = DB::table('server_cache_services')
            ->select('server_id', DB::raw('count(*) as c'))
            ->groupBy('server_id')
            ->havingRaw('count(*) > 1')
            ->get();
        if ($multi->isNotEmpty()) {
            throw new RuntimeException('Cannot rollback: some servers have multiple cache_services rows. Pick one per server to keep, delete the others, then retry.');
        }

        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine']);
            $table->unique('server_id');
        });
    }
};
