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
 * Result is cached per-server via the application cache for ~24h; the
 * workspace exposes a "Refresh data" button that calls forget() to bypass
 * the cache on demand (also called automatically after install/uninstall).
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

        $ttl = max(0, (int) config('server_cache.capabilities_cache_ttl_seconds', 86_400));
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

        // One row per (server, engine), port is always the engine default — so this engine-level
        // probe is enough for the tab-strip badges. `probeInstance()` is kept for callers that
        // need a single bool per row (the per-card reachability badge) but is now functionally
        // equivalent to a lookup into this map for any row with a default port.
        return [
            'redis' => $this->probeWith($server, $this->portPingCommand('redis', 6379), 'PONG'),
            'valkey' => $this->probeWith($server, $this->portPingCommand('valkey', 6379), 'PONG'),
            'memcached' => $this->probeWith($server, 'systemctl is-active memcached 2>/dev/null', 'active'),
            'keydb' => $this->probeWith($server, $this->portPingCommand('keydb', 6379), 'PONG'),
            'dragonfly' => $this->probeWith($server, 'systemctl is-active dragonfly 2>/dev/null', 'active'),
        ];
    }

    /**
     * Probe a single row on its actual port — kept for callers that want a single bool
     * (the per-card reachability badge) rather than the per-engine map {@see probe()} returns.
     * Post-collapse every row is on the engine default port, so `$port` typically matches
     * `ServerCacheService::defaultPortFor($engine)`; the parameter survives only because the
     * blade view passes `$row->port` literally.
     */
    public function probeInstance(Server $server, string $engine, int $port): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        return match ($engine) {
            'redis', 'valkey', 'keydb' => $this->probeWith($server, $this->portPingCommand($engine, $port), 'PONG'),
            'memcached' => $this->probeWith($server, 'systemctl is-active memcached 2>/dev/null', 'active'),
            'dragonfly' => $this->probeWith($server, 'systemctl is-active dragonfly 2>/dev/null', 'active'),
            default => false,
        };
    }

    /**
     * Resolve the right `*-cli -p <port> ping` command for a Redis-family engine.
     * Each engine's cli is preferred; redis-cli is the universal fallback because
     * all three (Redis, Valkey, KeyDB) speak RESP.
     */
    private function portPingCommand(string $engine, int $port): string
    {
        $primary = match ($engine) {
            'redis' => 'redis-cli',
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };

        return sprintf(
            '(command -v %1$s >/dev/null && %1$s -p %2$d ping 2>/dev/null) '
            .'|| (command -v redis-cli >/dev/null && redis-cli -p %2$d ping 2>/dev/null)',
            $primary,
            $port,
        );
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
     * Probe and cache the host's distro ID + codename from `/etc/os-release`. Used by the
     * workspace to gate the Install button for engines whose upstream package isn't published
     * for the host's distro (e.g. KeyDB on noble, Dragonfly on focal).
     *
     * Cached for a full day — codename doesn't change for the lifetime of a server. The probe
     * uses one cheap SSH round-trip; failure returns null (no gating, fall back to the install
     * script's own distro check which produces an actionable error if anything sneaks through).
     *
     * @return array{id: string, codename: string}|null
     */
    public function distroInfo(Server $server): ?array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return null;
        }

        $ttl = max(0, (int) config('server_cache.distro_cache_ttl_seconds', 86_400));
        $key = 'server.'.$server->id.'.cache_service_distro_v1';

        $resolve = function () use ($server): ?array {
            try {
                $out = $this->runner->run(
                    $server,
                    fn ($ssh): string => $ssh->exec(
                        "bash -lc '. /etc/os-release && echo \"\${ID:-}|\${VERSION_CODENAME:-}\"'",
                        30,
                    ),
                    useRoot: (bool) config('server_database.use_root_ssh', true),
                    fallbackToDeploy: (bool) config('server_database.fallback_to_deploy_user_ssh', true),
                );
            } catch (\Throwable) {
                return null;
            }

            $parts = explode('|', trim($out), 2);
            $id = strtolower(trim($parts[0] ?? ''));
            $codename = strtolower(trim($parts[1] ?? ''));

            // Treat empty fields as failure — caching `['id' => '', 'codename' => '']` for a day
            // would lock us into "unknown distro" until forget() runs.
            if ($id === '' || $codename === '') {
                return null;
            }

            return ['id' => $id, 'codename' => $codename];
        };

        if ($ttl === 0) {
            return $resolve();
        }

        return Cache::remember($key, $ttl, $resolve);
    }

    public function forgetDistro(Server $server): void
    {
        Cache::forget('server.'.$server->id.'.cache_service_distro_v1');
    }

    /**
     * Return a human-readable reason why an engine can't be auto-installed on this server, or
     * null when it's fine. The whitelist comes from
     * {@see CacheServiceInstallScripts::supportedDistroCodenames()} so the UI gate and the
     * install script speak the same vocabulary. Returns null on probe failure so the UI doesn't
     * grey out everything when SSH is flaky — the install script's own check is the final word.
     */
    public function engineUnsupportedReason(Server $server, string $engine): ?string
    {
        $whitelist = CacheServiceInstallScripts::supportedDistroCodenames($engine);
        if ($whitelist === null) {
            return null;
        }

        $info = $this->distroInfo($server);
        if ($info === null) {
            return null;
        }

        if (in_array($info['codename'], $whitelist, true)) {
            return null;
        }

        return match ($engine) {
            'keydb' => sprintf(
                'KeyDB upstream doesn\'t ship for %s %s. Use Ubuntu 20.04/22.04 or Debian 11/12, or pick Valkey/Redis on this host.',
                $info['id'],
                $info['codename'],
            ),
            'dragonfly' => sprintf(
                'Dragonfly\'s .deb doesn\'t resolve on %s %s. Use Ubuntu 22.04/24.04 or Debian 12.',
                $info['id'],
                $info['codename'],
            ),
            default => sprintf(
                '%s isn\'t supported on %s %s.',
                $engine,
                $info['id'],
                $info['codename'],
            ),
        };
    }

    /**
     * @return array{redis: bool, valkey: bool, memcached: bool, keydb: bool, dragonfly: bool}
     */
    protected function emptyResult(): array
    {
        return ['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false];
    }
}
