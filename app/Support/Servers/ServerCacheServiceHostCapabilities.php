<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Services\Servers\ServerSshConnectionRunner;
use Illuminate\Support\Facades\Cache;

/**
 * SSH-probe whether each cache engine is reachable on a server. Mirrors
 * `ServerDatabaseHostCapabilities` and is consumed by the WorkspaceCaches
 * Livewire component to gate the per-engine status UI.
 *
 * Result is cached per-server via the application cache for ~120s; the
 * workspace exposes a Recheck button that calls forget() to bypass the
 * cache after install/uninstall.
 *
 * Engines:
 *   - redis      → `redis-cli -p {port} ping` returns "PONG"
 *   - valkey     → `valkey-cli -p {port} ping` (falls back to redis-cli on
 *                   distros that ship valkey as a redis drop-in)
 *   - memcached  → `systemctl is-active memcached`
 *   - keydb      → `keydb-cli -p {port} ping`
 *   - dragonfly  → `redis-cli -p {port} ping` (Dragonfly is wire-compat)
 */
class ServerCacheServiceHostCapabilities
{
    public function __construct(
        protected ServerSshConnectionRunner $runner
    ) {}

    /**
     * @return array{redis: bool, valkey: bool, memcached: bool, keydb: bool, dragonfly: bool}
     */
    public function forServer(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return $this->emptyResult();
        }

        $ttl = max(0, (int) config('server_cache.capabilities_cache_ttl_seconds', 120));
        $key = 'server.'.$server->id.'.cache_service_capabilities_v1';

        if ($ttl === 0) {
            return $this->probe($server);
        }

        return Cache::remember($key, $ttl, fn () => $this->probe($server));
    }

    public function forget(Server $server): void
    {
        Cache::forget('server.'.$server->id.'.cache_service_capabilities_v1');
    }

    /**
     * @return array{redis: bool, valkey: bool, memcached: bool, keydb: bool, dragonfly: bool}
     */
    public function probe(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return $this->emptyResult();
        }

        return [
            'redis' => $this->probeWith($server, 'command -v redis-cli >/dev/null && redis-cli -p 6379 ping 2>/dev/null', 'PONG'),
            'valkey' => $this->probeWith($server, 'command -v valkey-cli >/dev/null && valkey-cli -p 6379 ping 2>/dev/null || (command -v redis-cli >/dev/null && redis-cli -p 6379 ping 2>/dev/null)', 'PONG'),
            'memcached' => $this->probeWith($server, 'systemctl is-active memcached 2>/dev/null', 'active'),
            'keydb' => $this->probeWith($server, 'command -v keydb-cli >/dev/null && keydb-cli -p 6379 ping 2>/dev/null', 'PONG'),
            'dragonfly' => $this->probeWith($server, 'systemctl is-active dragonfly 2>/dev/null', 'active'),
        ];
    }

    /**
     * Run `bash -lc <cmd>` over SSH and check whether the trimmed output starts with $expected.
     * Engines are mutually exclusive on a server (Phase 1 invariant), so a single false on a
     * misbehaving box won't prevent the operator from installing — it just prevents Dply from
     * showing a "running" badge until the next probe succeeds.
     */
    protected function probeWith(Server $server, string $command, string $expected): bool
    {
        try {
            $out = $this->runner->run(
                $server,
                fn ($ssh): string => $ssh->exec('bash -lc '.escapeshellarg($command), 30),
                useRoot: (bool) config('server_database.use_root_ssh', true),
                fallbackToDeploy: (bool) config('server_database.fallback_to_deploy_user_ssh', true),
            );
        } catch (\Throwable) {
            return false;
        }

        return str_contains(strtoupper(trim($out)), strtoupper($expected));
    }

    /**
     * @return array{redis: bool, valkey: bool, memcached: bool, keydb: bool, dragonfly: bool}
     */
    protected function emptyResult(): array
    {
        return ['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false];
    }
}
