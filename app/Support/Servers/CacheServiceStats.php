<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Lightweight per-engine stats snapshot for the Overview tab. Pulls a small set of fields from
 * `redis-cli INFO` (redis/valkey/keydb/dragonfly) or `echo stats | nc 127.0.0.1 11211` (memcached)
 * and reduces them to a flat array the Blade can render directly. Best-effort — returns an empty
 * snapshot when the SSH call fails or the engine binary isn't on PATH.
 */
class CacheServiceStats
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * @return array<string, string> Display-ready key/value pairs (label => value), already truncated.
     */
    public function snapshot(Server $server, ServerCacheService $cacheService): array
    {
        // Cache the per-engine snapshot for ~24h so the Overview tab loads instantly on
        // subsequent visits. Stats are inherently volatile, so the workspace surfaces a
        // "Refresh data" button that busts every engine's key on demand; restart/stop/flush
        // actions also bust the key explicitly via forget().
        $ttl = max(0, (int) config('server_cache.stats_cache_ttl_seconds', 86_400));
        $key = 'server.'.$server->id.'.cache_stats_v1.'.$cacheService->engine;

        $compute = function () use ($server, $cacheService): array {
            try {
                return match ($cacheService->engine) {
                    'memcached' => $this->memcachedStats($server, $cacheService->port),
                    default => $this->redisInfo($server, $cacheService),
                };
            } catch (\Throwable) {
                return [];
            }
        };

        return $ttl === 0 ? $compute() : Cache::remember($key, $ttl, $compute);
    }

    /**
     * Invalidate the cached stats for a server. Call this from any action that meaningfully
     * changes engine state (restart / stop / start / flush / install / uninstall / switch) so
     * the next render pulls fresh values.
     */
    public function forget(Server $server, string $engine): void
    {
        Cache::forget('server.'.$server->id.'.cache_stats_v1.'.$engine);
        Cache::forget('server.'.$server->id.'.cache_overview_v1.'.$engine);
    }

    /**
     * Richer, structured snapshot tuned for the dedicated-cache server Overview page tiles
     * (server_role redis/valkey). Returns numeric/typed fields instead of pre-formatted
     * label/value pairs so the view can compute ratios, bars, and relative timestamps.
     *
     * Short TTL (60s default) — the Overview is the "is my Redis healthy right now" page;
     * the 24h cache used by {@see snapshot()} is too stale. Memcached returns null because
     * memcached doesn't expose the redis-family fields the tile pack expects.
     *
     * @return array{
     *     reachable: bool,
     *     engine: string,
     *     version: ?string,
     *     uptime_seconds: ?int,
     *     uptime_human: ?string,
     *     connected_clients: ?int,
     *     ops_per_sec: ?int,
     *     used_memory_human: ?string,
     *     maxmemory_human: ?string,
     *     used_memory_pct: ?float,
     *     keyspace_hits: ?int,
     *     keyspace_misses: ?int,
     *     hit_rate: ?float,
     *     total_keys: ?int,
     *     rdb_last_save_at: ?CarbonInterface,
     *     aof_enabled: ?bool,
     *     role: ?string,
     *     connected_replicas: ?int,
     * }|null
     */
    public function overviewSnapshot(Server $server, ServerCacheService $cacheService): ?array
    {
        if (! ServerCacheService::engineSupportsAuth($cacheService->engine)) {
            return null;
        }

        $ttl = max(0, (int) config('server_cache.overview_cache_ttl_seconds', 60));
        $key = 'server.'.$server->id.'.cache_overview_v1.'.$cacheService->engine;

        $compute = function () use ($server, $cacheService): array {
            $raw = $this->rawInfo($server, $cacheService);
            if ($raw === null) {
                return $this->emptyOverviewPayload($cacheService->engine, reachable: false);
            }

            return $this->buildOverviewPayload($cacheService->engine, $this->parseRedisInfo($raw));
        };

        // Fail open if the cache backend itself is down — common foot-gun is the
        // dply app's own CACHE_STORE pointing at the very Redis box being managed.
        // Without this guard the page 502s the moment that box goes unreachable.
        try {
            return $ttl === 0 ? $compute() : Cache::remember($key, $ttl, $compute);
        } catch (\Throwable) {
            return $compute();
        }
    }

    /**
     * @param  array<string, string>  $parsed
     * @return array<string, mixed>
     */
    private function buildOverviewPayload(string $engine, array $parsed): array
    {
        $hits = isset($parsed['keyspace_hits']) ? (int) $parsed['keyspace_hits'] : null;
        $misses = isset($parsed['keyspace_misses']) ? (int) $parsed['keyspace_misses'] : null;
        $hitTotal = ($hits ?? 0) + ($misses ?? 0);
        $hitRate = $hitTotal > 0 ? ($hits ?? 0) / $hitTotal : null;

        // INFO emits `db0:keys=12,expires=3,avg_ttl=0` lines — sum the keys= component
        // across all databases so the tile reads "total keys on this box" not "db0 only".
        $totalKeys = null;
        foreach ($parsed as $k => $v) {
            if (! preg_match('/^db\d+$/', $k)) {
                continue;
            }
            if (preg_match('/keys=(\d+)/', (string) $v, $m)) {
                $totalKeys = ($totalKeys ?? 0) + (int) $m[1];
            }
        }

        $maxMemory = isset($parsed['maxmemory']) ? (int) $parsed['maxmemory'] : 0;
        $usedMemory = isset($parsed['used_memory']) ? (int) $parsed['used_memory'] : 0;
        $usedPct = $maxMemory > 0 ? min(100.0, ($usedMemory / $maxMemory) * 100) : null;

        $lastSave = isset($parsed['rdb_last_save_time']) ? (int) $parsed['rdb_last_save_time'] : null;
        $lastSaveAt = $lastSave !== null && $lastSave > 0 ? CarbonImmutable::createFromTimestamp($lastSave) : null;

        return [
            'reachable' => true,
            'engine' => $engine,
            'version' => isset($parsed['redis_version']) ? (string) $parsed['redis_version'] : null,
            'uptime_seconds' => isset($parsed['uptime_in_seconds']) ? (int) $parsed['uptime_in_seconds'] : null,
            'uptime_human' => isset($parsed['uptime_in_seconds']) ? $this->formatUptime((int) $parsed['uptime_in_seconds']) : null,
            'connected_clients' => isset($parsed['connected_clients']) ? (int) $parsed['connected_clients'] : null,
            'ops_per_sec' => isset($parsed['instantaneous_ops_per_sec']) ? (int) $parsed['instantaneous_ops_per_sec'] : null,
            'used_memory_human' => isset($parsed['used_memory_human']) ? (string) $parsed['used_memory_human'] : null,
            // INFO returns maxmemory_human as "0B" when unlimited; surface as null so the
            // view can render "—" rather than a misleading "0B" cap.
            'maxmemory_human' => $maxMemory > 0 && isset($parsed['maxmemory_human']) ? (string) $parsed['maxmemory_human'] : null,
            'used_memory_pct' => $usedPct,
            'keyspace_hits' => $hits,
            'keyspace_misses' => $misses,
            'hit_rate' => $hitRate,
            'total_keys' => $totalKeys,
            'rdb_last_save_at' => $lastSaveAt,
            'aof_enabled' => isset($parsed['aof_enabled']) ? ((int) $parsed['aof_enabled']) === 1 : null,
            'role' => isset($parsed['role']) ? (string) $parsed['role'] : null,
            'connected_replicas' => isset($parsed['connected_slaves']) ? (int) $parsed['connected_slaves'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOverviewPayload(string $engine, bool $reachable): array
    {
        return [
            'reachable' => $reachable,
            'engine' => $engine,
            'version' => null,
            'uptime_seconds' => null,
            'uptime_human' => null,
            'connected_clients' => null,
            'ops_per_sec' => null,
            'used_memory_human' => null,
            'maxmemory_human' => null,
            'used_memory_pct' => null,
            'keyspace_hits' => null,
            'keyspace_misses' => null,
            'hit_rate' => null,
            'total_keys' => null,
            'rdb_last_save_at' => null,
            'aof_enabled' => null,
            'role' => null,
            'connected_replicas' => null,
        ];
    }

    /**
     * The redis-family CLI binary for an engine. Centralised so the REPL, stats probe,
     * client list, and config writer all agree on which binary to invoke. Falls back
     * to redis-cli everywhere — every redis-family engine ships a wire-compatible
     * client.
     */
    public static function binaryFor(string $engine): string
    {
        return match ($engine) {
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };
    }

    /**
     * Run `INFO` against the engine and return the raw, unparsed buffer. Returns null on
     * SSH/auth/exit-code failure. Used by the keyspace dashboard to compute windowed deltas
     * over the cumulative counters that INFO reports — the parsed `snapshot()` view drops
     * most of them.
     */
    public function rawInfo(Server $server, ServerCacheService $cacheService): ?string
    {
        if (! ServerCacheService::engineSupportsAuth($cacheService->engine)) {
            return null;
        }

        $cli = self::binaryFor($cacheService->engine);
        // AUTH flag must come AFTER the cli binary — `-a 'pw' valkey-cli …`
        // makes bash treat `-a` as the program. `--no-auth-warning` keeps the
        // safety message off stderr (which we 2>&1 below) so the parser
        // doesn't see "Warning: …" prefixed onto the INFO buffer.
        $authFlag = filled($cacheService->auth_password ?? null)
            ? ' -a '.escapeshellarg((string) $cacheService->auth_password).' --no-auth-warning'
            : '';
        $cliPath = escapeshellarg($cli);
        $port = (int) $cacheService->port;

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'cache-service:info-raw:'.$cacheService->engine,
                'command -v '.$cliPath.' >/dev/null && '.$cliPath.$authFlag.' -p '.$port.' INFO 2>/dev/null || redis-cli'.$authFlag.' -p '.$port.' INFO 2>/dev/null',
                timeoutSeconds: 30,
                asRoot: false,
            );
        } catch (\Throwable) {
            return null;
        }

        if ($output->exitCode !== 0) {
            return null;
        }

        return $output->buffer;
    }

    /**
     * Pull the connected-client list for redis-family engines and parse the line-shaped output into
     * structured rows. Returns an empty array for memcached (no native equivalent) and on SSH/auth
     * failure — the UI surfaces an empty state in either case.
     *
     * Each row follows redis-cli's CLIENT LIST schema, e.g. `id=42 addr=127.0.0.1:55012 laddr=…
     * fd=14 name= age=300 idle=15 flags=N db=0 sub=0 …`. We pick a small subset for the table.
     *
     * @return list<array{id: string, addr: string, name: string, age: string, idle: string, db: string}>
     */
    public function clients(Server $server, ServerCacheService $cacheService): array
    {
        if (! ServerCacheService::engineSupportsAuth($cacheService->engine)) {
            // Memcached: no `CLIENT LIST` analogue. Caller hides the UI in this case; this guard
            // is defensive against a tampered tab/state.
            return [];
        }

        $cli = self::binaryFor($cacheService->engine);

        $authFlag = filled($cacheService->auth_password ?? null)
            ? ' -a '.escapeshellarg((string) $cacheService->auth_password).' --no-auth-warning'
            : '';
        $cliPath = escapeshellarg($cli);
        $port = (int) $cacheService->port;

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'cache-service:clients:'.$cacheService->engine,
                'command -v '.$cliPath.' >/dev/null && '.$cliPath.$authFlag.' -p '.$port.' CLIENT LIST 2>/dev/null || redis-cli'.$authFlag.' -p '.$port.' CLIENT LIST 2>/dev/null',
                timeoutSeconds: 30,
                asRoot: false,
            );
        } catch (\Throwable) {
            return [];
        }

        if ($output->exitCode !== 0) {
            return [];
        }

        $rows = [];
        foreach (explode("\n", $output->buffer) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Parse `key=value` tokens separated by spaces. Each value can contain anything except
            // spaces (CLIENT LIST guarantees this), so a simple split is enough.
            $kv = [];
            foreach (preg_split('/\s+/', $line) ?: [] as $token) {
                $eq = strpos($token, '=');
                if ($eq !== false) {
                    $kv[substr($token, 0, $eq)] = substr($token, $eq + 1);
                }
            }

            if (! isset($kv['addr'])) {
                continue;
            }

            $rows[] = [
                'id' => (string) ($kv['id'] ?? ''),
                'addr' => (string) ($kv['addr'] ?? ''),
                'name' => (string) ($kv['name'] ?? ''),
                'age' => (string) ($kv['age'] ?? ''),
                'idle' => (string) ($kv['idle'] ?? ''),
                'db' => (string) ($kv['db'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function redisInfo(Server $server, ServerCacheService $cacheService): array
    {
        $cli = self::binaryFor($cacheService->engine);
        // Missing AUTH here is why the Status-grid `snapshot()` row used to
        // come back empty whenever requirepass was set — the INFO call hit
        // NOAUTH and we silently dropped the buffer on the floor.
        $authFlag = filled($cacheService->auth_password ?? null)
            ? ' -a '.escapeshellarg((string) $cacheService->auth_password).' --no-auth-warning'
            : '';
        $cliPath = escapeshellarg($cli);
        $port = (int) $cacheService->port;

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:info:'.$cacheService->engine,
            'command -v '.$cliPath.' >/dev/null && '.$cliPath.$authFlag.' -p '.$port.' INFO 2>/dev/null || redis-cli'.$authFlag.' -p '.$port.' INFO 2>/dev/null',
            timeoutSeconds: 30,
            asRoot: false,
        );

        if ($output->exitCode !== 0) {
            return [];
        }

        $parsed = $this->parseRedisInfo($output->buffer);

        // Pick a small, useful set. Hidden fields (e.g. lazy_freed) stay out of the UI for v1.
        $picks = [
            'redis_version' => __('Version'),
            'uptime_in_seconds' => __('Uptime'),
            'connected_clients' => __('Connected clients'),
            'used_memory_human' => __('Memory used'),
            'maxmemory_human' => __('Max memory'),
            'total_commands_processed' => __('Total commands'),
            'keyspace_hits' => __('Keyspace hits'),
            'keyspace_misses' => __('Keyspace misses'),
        ];

        $result = [];
        foreach ($picks as $key => $label) {
            if (! isset($parsed[$key])) {
                continue;
            }
            $value = $parsed[$key];
            if ($key === 'uptime_in_seconds') {
                $value = $this->formatUptime((int) $value);
            }
            $result[$label] = (string) $value;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function memcachedStats(Server $server, int $port): array
    {
        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:stats:memcached',
            "(printf 'stats\\nquit\\n' | timeout 5 nc -q 1 127.0.0.1 ".(int) $port.') 2>/dev/null',
            timeoutSeconds: 30,
            asRoot: false,
        );

        if ($output->exitCode !== 0) {
            return [];
        }

        $parsed = [];
        foreach (explode("\n", $output->buffer) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, 'STAT ')) {
                continue;
            }
            $parts = explode(' ', $line, 3);
            if (count($parts) === 3) {
                $parsed[$parts[1]] = $parts[2];
            }
        }

        $picks = [
            'version' => __('Version'),
            'uptime' => __('Uptime'),
            'curr_connections' => __('Connections'),
            'curr_items' => __('Items'),
            'bytes' => __('Memory used'),
            'cmd_get' => __('Total GETs'),
            'cmd_set' => __('Total SETs'),
            'evictions' => __('Evictions'),
        ];

        $result = [];
        foreach ($picks as $key => $label) {
            if (! isset($parsed[$key])) {
                continue;
            }
            $value = $parsed[$key];
            if ($key === 'uptime') {
                $value = $this->formatUptime((int) $value);
            } elseif ($key === 'bytes') {
                $value = $this->formatBytes((int) $value);
            }
            $result[$label] = (string) $value;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function parseRedisInfo(string $raw): array
    {
        $parsed = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $kv = explode(':', $line, 2);
            if (count($kv) === 2) {
                $parsed[$kv[0]] = $kv[1];
            }
        }

        return $parsed;
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($days > 0) {
            $parts[] = $days.'d';
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours.'h';
        }
        $parts[] = $minutes.'m';

        return implode(' ', $parts);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
