<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
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
    /**
     * Per-request memo so multiple {@see engineUnsupportedReason()} calls for the same
     * server (e.g. keydb + dragonfly in WorkspaceCaches::render()) share one cache read.
     *
     * @var array<string, array{id: string, codename: string}|null>
     */
    private array $distroInfoMemory = [];

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
        // Best-effort — when the dply control plane's CACHE_STORE points at
        // the very Redis box being managed and that box is in MISCONF (BGSAVE
        // failed → writes refused), the DEL itself throws RedisException and
        // crashes the page. Swallow it: the cache entry expires on its TTL
        // anyway, so failing to bust it just means slightly stale capability
        // data until the next natural expiry.
        try {
            Cache::forget('server.'.$server->id.'.cache_service_capabilities_v1');
        } catch (\Throwable) {
            // swallow — see above
        }
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
        //
        // All five checks run in a SINGLE SSH round-trip rather than one handshake per engine
        // (multiplexing is off by default). Redis-family liveness = the ping output contains
        // PONG; memcached/dragonfly liveness = `systemctl is-active --quiet` exit status.
        //
        // NOTE: the old probeWith() did a substring match on `is-active` output for memcached
        // and dragonfly, which wrongly treated "inactive" as active (it contains "active").
        // `--quiet` is exit-code based, so a stopped/absent unit now correctly reads false.
        $redisCmd = $this->portPingCommand($server, 'redis', 6379);
        $valkeyCmd = $this->portPingCommand($server, 'valkey', 6379);
        $keydbCmd = $this->portPingCommand($server, 'keydb', 6379);

        $script = <<<BASH
        set +e
        REDIS=0; VALKEY=0; MEMCACHED=0; KEYDB=0; DRAGONFLY=0
        case "\$( { $redisCmd ; } 2>/dev/null | tr a-z A-Z )" in *PONG*) REDIS=1;; esac
        case "\$( { $valkeyCmd ; } 2>/dev/null | tr a-z A-Z )" in *PONG*) VALKEY=1;; esac
        systemctl is-active --quiet memcached 2>/dev/null && MEMCACHED=1
        case "\$( { $keydbCmd ; } 2>/dev/null | tr a-z A-Z )" in *PONG*) KEYDB=1;; esac
        systemctl is-active --quiet dragonfly 2>/dev/null && DRAGONFLY=1
        echo "DPLY_CACHE_CAPS redis=\$REDIS valkey=\$VALKEY memcached=\$MEMCACHED keydb=\$KEYDB dragonfly=\$DRAGONFLY"
        BASH;

        try {
            $out = $this->runner->run(
                $server,
                fn ($ssh): string => $ssh->exec('bash -lc '.escapeshellarg($script), 45),
                useRoot: (bool) config('server_cache.probe_use_root_ssh', false),
                fallbackToDeploy: (bool) config('server_database.fallback_to_deploy_user_ssh', true),
            );
        } catch (\Throwable) {
            return $this->emptyResult();
        }

        if (! preg_match('/DPLY_CACHE_CAPS\s+([^\n]*)/', $out, $m)) {
            return $this->emptyResult();
        }

        $caps = $this->emptyResult();
        foreach (array_keys($caps) as $engine) {
            if (preg_match('/\b'.$engine.'=([01])\b/', $m[1], $mm)) {
                $caps[$engine] = $mm[1] === '1';
            }
        }

        return $caps;
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

        // memcached/dragonfly: `--quiet` + `&& echo ACTIVE` makes this exit-code based —
        // probeWith() ignores exit status, so without the marker a substring match on raw
        // `is-active` output treats "inactive" as active (it contains "active").
        return match ($engine) {
            'redis', 'valkey', 'keydb' => $this->probeWith($server, $this->portPingCommand($server, $engine, $port), 'PONG'),
            'memcached' => $this->probeWith($server, 'systemctl is-active --quiet memcached 2>/dev/null && echo ACTIVE', 'active'),
            'dragonfly' => $this->probeWith($server, 'systemctl is-active --quiet dragonfly 2>/dev/null && echo ACTIVE', 'active'),
            default => false,
        };
    }

    /**
     * Resolve the right `*-cli -p <port> ping` command for a Redis-family engine.
     * Each engine's cli is preferred; redis-cli is the universal fallback because
     * all three (Redis, Valkey, KeyDB) speak RESP. When the engine has an AUTH
     * password configured on its {@see ServerCacheService} row, the `-a` flag is
     * appended — without it the engine returns NOAUTH and the probe wrongly
     * reports the engine as unreachable.
     */
    private function portPingCommand(Server $server, string $engine, int $port): string
    {
        $primary = match ($engine) {
            'redis' => 'redis-cli',
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };

        $authFlag = '';
        $row = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->where('engine', $engine)
            ->first();
        if ($row && filled($row->auth_password ?? null)) {
            $authFlag = '-a '.escapeshellarg((string) $row->auth_password).' --no-auth-warning ';
        }

        return sprintf(
            '(command -v %1$s >/dev/null && %1$s %3$s-p %2$d ping 2>/dev/null) '
            .'|| (command -v redis-cli >/dev/null && redis-cli %3$s-p %2$d ping 2>/dev/null)',
            $primary,
            $port,
            $authFlag,
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
                useRoot: (bool) config('server_cache.probe_use_root_ssh', false),
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

        $memoryKey = (string) $server->id;
        if (array_key_exists($memoryKey, $this->distroInfoMemory)) {
            return $this->distroInfoMemory[$memoryKey];
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
                    useRoot: (bool) config('server_cache.probe_use_root_ssh', false),
                    fallbackToDeploy: (bool) config('server_database.fallback_to_deploy_user_ssh', true),
                );
            } catch (\Throwable) {
                return null;
            }

            $parts = explode('|', trim($out), 2);
            $id = strtolower(trim($parts[0]));
            $codename = strtolower(trim($parts[1] ?? ''));

            // Treat empty fields as failure — caching `['id' => '', 'codename' => '']` for a day
            // would lock us into "unknown distro" until forget() runs.
            if ($id === '' || $codename === '') {
                return null;
            }

            return ['id' => $id, 'codename' => $codename];
        };

        $info = $ttl === 0 ? $resolve() : Cache::remember($key, $ttl, $resolve);
        $this->distroInfoMemory[$memoryKey] = $info;

        return $info;
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
    /**
     * @return array<string, string|null>
     */
    public function unsupportedReasonsByEngine(Server $server): array
    {
        $info = $this->distroInfo($server);
        $reasons = [];

        foreach (CacheServiceInstallScripts::supportedEngines() as $engine) {
            $reasons[$engine] = $this->unsupportedReasonForEngine($info, $engine);
        }

        return $reasons;
    }

    public function engineUnsupportedReason(Server $server, string $engine): ?string
    {
        return $this->unsupportedReasonForEngine($this->distroInfo($server), $engine);
    }

    /**
     * @param  array<string, mixed> $info
     */
    private function unsupportedReasonForEngine(?array $info, string $engine): ?string
    {
        $whitelist = CacheServiceInstallScripts::supportedDistroCodenames($engine);
        if ($whitelist === null) {
            return null;
        }

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
