<?php

namespace App\Services\WorkerPools;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerFirewallRule;
use App\Models\WorkerPool;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Support\Servers\CacheServiceNetworkExposure;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Support\Facades\Log;

/**
 * Opens a pool's private backends to its cross-region workers.
 *
 * Access model (the multi-IP-safe pattern proven elsewhere in the codebase):
 *  - Database: bind publicly + password-gated `pg_hba` (`0.0.0.0/0` scram), but
 *    admit only the specific worker `/32`s at the FIREWALL — one ServerFirewallRule
 *    per worker IP, tagged per pool. So the DB ACL is the password and the network
 *    ACL is the per-IP UFW rule; arbitrary IPs can't even connect.
 *  - Redis/cache: flip the bind to public + per-`/32` firewall rules. The password
 *    is NOT rotated here (that would desync every worker's REDIS_PASSWORD); if the
 *    cache has no auth, that's surfaced as a warning rather than silently exposing.
 *
 * Idempotent: re-running only adds missing firewall rules. Rules are tagged
 * `worker-pool:{poolId}` so {@see pruneForMember()} can remove a torn-down
 * worker's grants on scale-down.
 */
class WorkerPoolExposureApplier
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
        private readonly ServerFirewallProvisioner $firewall,
        private readonly CacheServiceNetworkExposure $cacheExposure,
    ) {}

    /**
     * @return array{applied: list<string>, warnings: list<string>}
     */
    public function applyForPool(WorkerPool $pool, ?string $actorId = null): array
    {
        $pool->load('servers');

        // Cross-region members that are up and have a public IP.
        $members = $pool->servers->filter(function (Server $s): bool {
            return (bool) ($s->meta['cross_region'] ?? false)
                && filled($s->ip_address)
                && $s->poolMemberState() !== WorkerPool::MEMBER_DRAINING;
        });
        $cidrs = $members->map(fn (Server $s): string => $s->ip_address.'/32')->unique()->values()->all();
        if ($cidrs === []) {
            return ['applied' => [], 'warnings' => []];
        }

        // Union the exposure plans recorded on each member: backend server_id → ports.
        $backends = []; // server_id => set<int port>
        foreach ($members as $member) {
            foreach (($member->meta['pool']['exposures'] ?? []) as $e) {
                $sid = (string) ($e['server_id'] ?? '');
                if ($sid === '') {
                    continue;
                }
                $backends[$sid] = array_values(array_unique([...($backends[$sid] ?? []), ...array_map('intval', $e['ports'] ?? [])]));
            }
        }

        $applied = [];
        $warnings = [];
        foreach ($backends as $serverId => $ports) {
            $backend = Server::query()->find($serverId);
            if (! $backend instanceof Server) {
                continue;
            }
            foreach ($ports as $port) {
                $this->exposeBackendPort($pool, $backend, (int) $port, $cidrs, $actorId, $applied, $warnings);
            }
        }

        return ['applied' => $applied, 'warnings' => $warnings];
    }

    /**
     * @param  list<string>  $cidrs
     * @param  list<string>  $applied
     * @param  list<string>  $warnings
     */
    private function exposeBackendPort(WorkerPool $pool, Server $backend, int $port, array $cidrs, ?string $actorId, array &$applied, array &$warnings): void
    {
        $engine = $backend->databaseEngines()->where('port', $port)->first();
        if ($engine instanceof ServerDatabaseEngine) {
            $this->exposeDatabase($pool, $backend, $engine, $cidrs, $applied, $warnings);

            return;
        }

        $cache = ServerCacheService::query()->where('server_id', $backend->id)->where('port', $port)->first();
        if ($cache instanceof ServerCacheService) {
            $this->exposeCache($pool, $backend, $cache, $cidrs, $actorId, $applied, $warnings);

            return;
        }

        $warnings[] = __('No database engine or cache service found on :server port :port — expose it manually.', [
            'server' => $backend->name,
            'port' => $port,
        ]);
    }

    /**
     * @param  list<string>  $cidrs
     * @param  list<string>  $applied
     * @param  list<string>  $warnings
     */
    private function exposeDatabase(WorkerPool $pool, Server $backend, ServerDatabaseEngine $engine, array $cidrs, array &$applied, array &$warnings): void
    {
        try {
            // Bind publicly + password-gated pg_hba (0.0.0.0/0 scram). The actual
            // network gate is the per-/32 firewall rules below.
            $script = DatabaseEngineInstallScripts::enableRemoteAccessScript((string) $engine->engine, '0.0.0.0/0');
            $out = $this->executor->runInlineBash(
                $backend,
                'worker-pool:db-expose:'.$engine->engine,
                $script,
                timeoutSeconds: 120,
                asRoot: true,
            );
            if ($out->exitCode !== 0) {
                $warnings[] = __('Enabling remote access on :server (:engine) reported a non-zero exit — check the database manually.', [
                    'server' => $backend->name,
                    'engine' => $engine->engine,
                ]);
            }
            $engine->update(['remote_access' => true, 'allowed_from' => implode(',', $cidrs)]);
        } catch (\Throwable $e) {
            Log::warning('worker-pool: db expose failed', ['server_id' => $backend->id, 'error' => $e->getMessage()]);
            $warnings[] = __('Could not enable remote access on :server: :err', ['server' => $backend->name, 'err' => $e->getMessage()]);

            return;
        }

        $this->ensureFirewallRules($pool, $backend, (int) $engine->port, $cidrs, 'db:'.$engine->engine);
        $applied[] = __(':engine on :server now accepts the pool workers (password-gated, firewalled to their IPs).', [
            'engine' => strtoupper((string) $engine->engine),
            'server' => $backend->name,
        ]);
    }

    /**
     * @param  list<string>  $cidrs
     * @param  list<string>  $applied
     * @param  list<string>  $warnings
     */
    private function exposeCache(WorkerPool $pool, Server $backend, ServerCacheService $cache, array $cidrs, ?string $actorId, array &$applied, array &$warnings): void
    {
        if (blank($cache->auth_password)) {
            $warnings[] = __(':engine on :server has no password — exposing it publicly is unsafe. Set a password (and update REDIS_PASSWORD on the sites) before relying on cross-region workers.', [
                'engine' => ucfirst((string) $cache->engine),
                'server' => $backend->name,
            ]);
        }

        try {
            // Flip the bind to public + first firewall rule via the existing
            // service; remaining /32s are added below.
            $this->cacheExposure->expose($backend, $cache, $cidrs[0], $actorId);
        } catch (\Throwable $e) {
            Log::warning('worker-pool: cache expose failed', ['server_id' => $backend->id, 'error' => $e->getMessage()]);
            $warnings[] = __('Could not expose :engine on :server: :err', ['engine' => $cache->engine, 'server' => $backend->name, 'err' => $e->getMessage()]);

            return;
        }

        $this->ensureFirewallRules($pool, $backend, (int) $cache->port, $cidrs, 'cache:'.$cache->engine);
        $applied[] = __(':engine on :server now accepts the pool workers (firewalled to their IPs).', [
            'engine' => ucfirst((string) $cache->engine),
            'server' => $backend->name,
        ]);
    }

    /**
     * Create + apply one allow rule per worker /32 (idempotent), tagged for the pool.
     *
     * @param  list<string>  $cidrs
     */
    private function ensureFirewallRules(WorkerPool $pool, Server $backend, int $port, array $cidrs, string $label): void
    {
        $poolTag = 'worker-pool:'.$pool->id;
        foreach ($cidrs as $cidr) {
            $exists = ServerFirewallRule::query()
                ->where('server_id', $backend->id)
                ->where('port', $port)
                ->where('source', $cidr)
                ->whereJsonContains('tags', $poolTag)
                ->exists();
            if ($exists) {
                continue;
            }

            $rule = ServerFirewallRule::query()->create([
                'server_id' => $backend->id,
                'name' => 'Worker pool '.$label.' '.$cidr,
                'port' => $port,
                'protocol' => 'tcp',
                'source' => $cidr,
                'action' => 'allow',
                'enabled' => true,
                'sort_order' => (int) (ServerFirewallRule::query()->where('server_id', $backend->id)->max('sort_order') ?? 0) + 1,
                'tags' => ['dply-worker-pool', $poolTag],
            ]);

            try {
                $this->firewall->applyRule($backend, $rule);
            } catch (\Throwable $e) {
                Log::warning('worker-pool: applyRule failed', ['server_id' => $backend->id, 'cidr' => $cidr, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Remove a torn-down worker's allow rules across all backends in the pool
     * (called on scale-down). Best-effort.
     */
    public function pruneForMember(WorkerPool $pool, Server $member): void
    {
        $cidr = filled($member->ip_address) ? $member->ip_address.'/32' : null;
        if ($cidr === null) {
            return;
        }

        $poolTag = 'worker-pool:'.$pool->id;
        $rules = ServerFirewallRule::query()
            ->where('source', $cidr)
            ->whereJsonContains('tags', $poolTag)
            ->get();

        foreach ($rules as $rule) {
            $backend = Server::query()->find($rule->server_id);
            if ($backend instanceof Server) {
                try {
                    $this->firewall->removeFromHost($backend, $rule);
                } catch (\Throwable $e) {
                    Log::info('worker-pool: prune removeFromHost failed', ['rule_id' => $rule->id, 'error' => $e->getMessage()]);
                }
            }
            $rule->delete();
        }
    }
}
