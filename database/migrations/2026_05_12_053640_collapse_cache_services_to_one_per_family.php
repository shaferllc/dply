<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Collapse multi-engine + multi-instance cache services down to the new rule:
 *
 *   At most one row from the redis-family (redis | valkey | keydb | dragonfly) per server,
 *   plus optionally one memcached row.
 *
 * The recent post-mortem on KeyDB-on-noble orphan rows + default-instance-on-non-default-port
 * config drift made it clear the multi-instance machinery was costing more in bugs and surface
 * area than it was saving in real workloads. Most operators run one cache; a minority run Redis
 * + Memcached side-by-side (the only coexistence pattern this rule preserves).
 *
 * Up-migration is destructive of multi-row state: for each (server_id, family) group we keep
 * one row and `force_removed`-audit the rest. No SSH cleanup happens here — the box-side
 * state (apt packages, systemd units, data dirs) for the removed engines is left as-is, and
 * the operator is on the hook for `apt purge` / `systemctl disable` if they want it gone.
 * That matches the "auto-pick RUNNING, force-remove the rest" policy we settled on.
 *
 * Down-migration restores the old (server_id, port) + (server_id, engine, name) shape. The
 * partial unique index goes away on the way down so a future re-up of the multi-engine
 * migration can add it back if we ever change course.
 */
return new class extends Migration
{
    /**
     * Engines that share the Redis wire protocol on port 6379. Duplicates
     * App\Models\ServerCacheService::FAMILY_REDIS_ENGINES intentionally — the migration must be
     * runnable independent of model-class changes, and the constant value won't churn.
     *
     * @var list<string>
     */
    private const REDIS_FAMILY = ['redis', 'valkey', 'keydb', 'dragonfly'];

    public function up(): void
    {
        DB::transaction(function () {
            $this->collapseRowsPerFamily();
            $this->normaliseInstanceNamesToDefault();
        });

        // Constraint changes after the data is clean — otherwise `unique(server_id, engine)`
        // would fire on the existing multi-instance rows before the collapse runs.
        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine', 'name']);
            $table->dropUnique(['server_id', 'port']);
            $table->unique(['server_id', 'engine']);
        });

        // Partial unique index for the redis-family coexistence rule. Postgres-only; SQLite
        // dev/test environments get the same effect via the application-layer guard in
        // WorkspaceCaches::installCacheService(). The driver check keeps the migration
        // runnable on both — tests use SQLite (per phpunit.xml) and don't need the partial
        // index because their fixtures don't exercise the violation path.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "CREATE UNIQUE INDEX %s ON server_cache_services (server_id) WHERE engine IN ('%s')",
                'server_cache_services_one_redis_family_per_server',
                implode("','", self::REDIS_FAMILY),
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS server_cache_services_one_redis_family_per_server');
        }

        Schema::table('server_cache_services', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'engine']);
            $table->unique(['server_id', 'port']);
            $table->unique(['server_id', 'engine', 'name']);
        });

        // We don't restore the deleted rows — that data is gone. The audit events written in
        // up() are the forensic record. Operators rolling back AND wanting the old multi-engine
        // state must re-install the extras manually.
    }

    /**
     * For each (server, family) pair pick one keeper row, soft-delete the others by direct
     * `delete()` (no UninstallCacheServiceJob — we explicitly don't touch the box), and emit a
     * `force_removed` audit event so the trail isn't lost.
     */
    private function collapseRowsPerFamily(): void
    {
        $redisList = "'".implode("','", self::REDIS_FAMILY)."'";

        // Rank rows within each (server_id, family) bucket. Status preference matches the
        // policy: keep what's actually running, fall back to in-flight installs (don't kill
        // an apt install mid-flight), then oldest-by-created_at as the tiebreaker.
        //
        // status_order: 0=running, 1=installing, 2=anything else, ordered ascending so 0 wins.
        // We compute family inline rather than denormalising it onto the table — the migration
        // runs once and the partial unique index does the steady-state enforcement.
        $sql = "
            SELECT
                id,
                server_id,
                engine,
                name,
                status,
                port,
                CASE WHEN engine = 'memcached' THEN 'memcached' ELSE 'redis-family' END AS family,
                CASE
                    WHEN status = 'running' THEN 0
                    WHEN status = 'installing' THEN 1
                    ELSE 2
                END AS status_order,
                created_at
            FROM server_cache_services
            ORDER BY server_id ASC, family ASC, status_order ASC, created_at ASC
        ";

        $rows = collect(DB::select($sql));

        // Group in PHP rather than rely on database-specific window functions (Postgres supports
        // DISTINCT ON, SQLite doesn't). The query above already pre-sorts so the first row in
        // each group is the keeper.
        $grouped = $rows->groupBy(fn ($row) => $row->server_id.'|'.$row->family);

        foreach ($grouped as $group) {
            $keeper = $group->first();
            $losers = $group->slice(1);

            foreach ($losers as $loser) {
                DB::table('server_cache_service_audit_events')->insert([
                    'id' => (string) Str::ulid(),
                    'server_id' => $loser->server_id,
                    'user_id' => null,
                    'event' => 'force_removed',
                    'meta' => json_encode([
                        'engine' => $loser->engine,
                        'name' => $loser->name,
                        'port' => (int) $loser->port,
                        'previous_status' => $loser->status,
                        'reason' => 'family_collapse',
                        'keeper_id' => $keeper->id,
                        'keeper_engine' => $keeper->engine,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('server_cache_services')->where('id', $loser->id)->delete();
            }
        }
    }

    /**
     * After the collapse, anything still around should be the single canonical row per (server,
     * family). New code only ever writes `name = 'default'`, but legacy named-instance rows that
     * happened to be the keeper for their group could still have a non-default name — flatten
     * those now so the install scripts (which only know the legacy default-path) don't try to
     * read `/etc/redis/redis-<name>.conf` after the templated-config code is deleted.
     *
     * The box-side per-instance state (e.g. `/etc/redis/redis-sessions.conf`,
     * `/var/lib/redis/sessions`) is left in place — it doesn't conflict with the legacy paths
     * the keeper is now expected to use, and the operator may want to keep the data.
     */
    private function normaliseInstanceNamesToDefault(): void
    {
        DB::table('server_cache_services')
            ->where('name', '!=', 'default')
            ->update(['name' => 'default']);
    }
};
