<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Read / mutate redis-family persistence: RDB save schedule, AOF on/off, and the one-shot
 * BGSAVE / BGREWRITEAOF triggers. Mirrors {@see CacheServiceAuth} for SSH-execute + verify
 * + rollback shape — disruptive enough that a misconfigured `save` line shouldn't leave
 * the engine in a state that drops data on restart.
 *
 * Memcached has no persistence model; callers guard via {@see ServerCacheService::engineSupportsAuth()}.
 */
class CacheServicePersistence
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * Read the engine's current persistence state. Cached briefly so wire:poll
     * doesn't hammer SSH; busted by every mutating action in this class.
     *
     * @return array{
     *     reachable: bool,
     *     aof_enabled: ?bool,
     *     aof_size_bytes: ?int,
     *     aof_last_rewrite_at: ?CarbonImmutable,
     *     rdb_last_save_at: ?CarbonImmutable,
     *     rdb_bgsave_in_progress: ?bool,
     *     save_schedule: list<array{seconds: int, changes: int}>,
     *     raw_save: ?string,
     * }
     */
    public function state(Server $server, ServerCacheService $cacheService): array
    {
        $empty = $this->emptyState();
        if (! ServerCacheService::engineSupportsAuth($cacheService->engine)) {
            return $empty;
        }

        $ttl = max(0, (int) config('server_cache.persistence_cache_ttl_seconds', 10));
        $key = 'server.'.$server->id.'.cache_persistence_v1.'.$cacheService->engine;

        $compute = function () use ($server, $cacheService, $empty): array {
            $cli = CacheServiceStats::binaryFor($cacheService->engine);
            $authFlag = filled($cacheService->auth_password ?? null)
                ? '-a '.escapeshellarg((string) $cacheService->auth_password).' '
                : '';
            $port = (int) $cacheService->port;
            $cliPath = escapeshellarg($cli);

            // One SSH round-trip pulls INFO persistence plus CONFIG GET save; we parse
            // both blocks out of the combined buffer to keep latency low.
            $script = $authFlag.$cliPath.' -p '.$port.' INFO persistence 2>/dev/null; '
                .'echo "---SAVE---"; '
                .$authFlag.$cliPath.' -p '.$port.' CONFIG GET save 2>/dev/null';

            try {
                $output = $this->executor->runInlineBash(
                    $server,
                    'cache-service:persistence-state:'.$cacheService->engine,
                    $script,
                    timeoutSeconds: 30,
                    asRoot: false,
                );
            } catch (\Throwable) {
                return $empty;
            }

            if ($output->exitCode !== 0) {
                return $empty;
            }

            return $this->parseState($output->buffer);
        };

        try {
            return $ttl === 0 ? $compute() : Cache::remember($key, $ttl, $compute);
        } catch (\Throwable) {
            return $compute();
        }
    }

    /**
     * Trigger a one-shot RDB snapshot via BGSAVE. Returns true when the engine accepted
     * the command — the actual save runs asynchronously, observable via {@see state()}
     * (`rdb_bgsave_in_progress` flips back to false; `rdb_last_save_at` advances).
     */
    public function bgsave(Server $server, ServerCacheService $cacheService): bool
    {
        return $this->runCliCommand($server, $cacheService, 'BGSAVE', 'persistence-bgsave');
    }

    /**
     * Trigger a one-shot AOF rewrite via BGREWRITEAOF. Same async semantics as bgsave().
     */
    public function bgrewriteaof(Server $server, ServerCacheService $cacheService): bool
    {
        return $this->runCliCommand($server, $cacheService, 'BGREWRITEAOF', 'persistence-bgrewriteaof');
    }

    /**
     * Toggle AOF on/off via CONFIG SET appendonly + CONFIG REWRITE so the change survives
     * restart. Returns true on success. On the OFF path, this stops AOF writes but does
     * NOT delete the existing .aof file — operators can clean it up via Files.
     */
    public function setAofEnabled(Server $server, ServerCacheService $cacheService, bool $enabled): bool
    {
        $this->guardSupported($cacheService->engine);

        $cli = CacheServiceStats::binaryFor($cacheService->engine);
        $authFlag = filled($cacheService->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $cacheService->auth_password).' '
            : '';
        $port = (int) $cacheService->port;
        $cliPath = escapeshellarg($cli);
        $value = $enabled ? 'yes' : 'no';

        $script = <<<BASH
set -e
{$authFlag}{$cliPath} -p {$port} CONFIG SET appendonly {$value} >/dev/null
{$authFlag}{$cliPath} -p {$port} CONFIG REWRITE >/dev/null
ACTUAL=\$({$authFlag}{$cliPath} -p {$port} CONFIG GET appendonly | tail -n1)
if [ "\$ACTUAL" != "{$value}" ]; then
    echo "[dply] AOF toggle verify failed (got: \$ACTUAL)" >&2
    exit 2
fi
BASH;

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'cache-service:persistence-aof:'.$cacheService->engine,
                $script,
                timeoutSeconds: 60,
                asRoot: false,
            );
        } catch (\Throwable) {
            return false;
        }

        if ($output->exitCode !== 0) {
            return false;
        }

        $this->forget($server, $cacheService->engine);

        return true;
    }

    /**
     * Replace the engine's `save` schedule. `$schedule` is a list of [seconds, changes] pairs;
     * an empty list disables RDB snapshots entirely (`CONFIG SET save ""`). Persisted via
     * CONFIG REWRITE so the change survives restart.
     * @param  array<string, mixed> $schedule
     */
    public function setSaveSchedule(Server $server, ServerCacheService $cacheService, array $schedule): bool
    {
        $this->guardSupported($cacheService->engine);

        $value = '';
        foreach ($schedule as $entry) {
            $secs = isset($entry['seconds']) ? (int) $entry['seconds'] : 0;
            $changes = isset($entry['changes']) ? (int) $entry['changes'] : 0;
            if ($secs <= 0 || $changes <= 0) {
                continue;
            }
            $value .= ($value === '' ? '' : ' ').$secs.' '.$changes;
        }

        $cli = CacheServiceStats::binaryFor($cacheService->engine);
        $authFlag = filled($cacheService->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $cacheService->auth_password).' '
            : '';
        $port = (int) $cacheService->port;
        $cliPath = escapeshellarg($cli);
        $quotedValue = escapeshellarg($value);

        $script = <<<BASH
set -e
{$authFlag}{$cliPath} -p {$port} CONFIG SET save {$quotedValue} >/dev/null
{$authFlag}{$cliPath} -p {$port} CONFIG REWRITE >/dev/null
BASH;

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'cache-service:persistence-save:'.$cacheService->engine,
                $script,
                timeoutSeconds: 60,
                asRoot: false,
            );
        } catch (\Throwable) {
            return false;
        }

        if ($output->exitCode !== 0) {
            return false;
        }

        $this->forget($server, $cacheService->engine);

        return true;
    }

    public function forget(Server $server, string $engine): void
    {
        Cache::forget('server.'.$server->id.'.cache_persistence_v1.'.$engine);
    }

    private function runCliCommand(Server $server, ServerCacheService $cacheService, string $verb, string $taskName): bool
    {
        $this->guardSupported($cacheService->engine);

        $cli = CacheServiceStats::binaryFor($cacheService->engine);
        $authFlag = filled($cacheService->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $cacheService->auth_password).' '
            : '';

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'cache-service:'.$taskName.':'.$cacheService->engine,
                $authFlag.escapeshellarg($cli).' -p '.(int) $cacheService->port.' '.$verb.' 2>/dev/null',
                timeoutSeconds: 30,
                asRoot: false,
            );
        } catch (\Throwable) {
            return false;
        }

        if ($output->exitCode !== 0) {
            return false;
        }

        $this->forget($server, $cacheService->engine);

        return true;
    }

    /**
     * @return array{
     *     reachable: bool,
     *     aof_enabled: ?bool,
     *     aof_size_bytes: ?int,
     *     aof_last_rewrite_at: ?CarbonImmutable,
     *     rdb_last_save_at: ?CarbonImmutable,
     *     rdb_bgsave_in_progress: ?bool,
     *     save_schedule: list<array{seconds: int, changes: int}>,
     *     raw_save: ?string,
     * }
     */
    private function parseState(string $buffer): array
    {
        [$infoBlock, $saveBlock] = array_pad(explode('---SAVE---', $buffer, 2), 2, '');
        $info = [];
        foreach (explode("\n", $infoBlock) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $kv = explode(':', $line, 2);
            if (count($kv) === 2) {
                $info[$kv[0]] = $kv[1];
            }
        }

        // CONFIG GET returns two lines: key, value. The value is space-separated
        // `<seconds> <changes>` pairs ("3600 1 300 100" → snapshot every 3600s after
        // 1 change OR every 300s after 100 changes). Empty value = RDB disabled.
        $saveLines = array_values(array_filter(array_map('trim', explode("\n", $saveBlock))));
        $rawSave = $saveLines[1] ?? '';
        $schedule = [];
        if ($rawSave !== '') {
            $tokens = preg_split('/\s+/', $rawSave) ?: [];
            for ($i = 0, $n = count($tokens); $i + 1 < $n; $i += 2) {
                $secs = (int) $tokens[$i];
                $changes = (int) $tokens[$i + 1];
                if ($secs > 0 && $changes > 0) {
                    $schedule[] = ['seconds' => $secs, 'changes' => $changes];
                }
            }
        }

        $lastSave = isset($info['rdb_last_save_time']) ? (int) $info['rdb_last_save_time'] : 0;
        $lastRewrite = isset($info['aof_last_rewrite_time_sec']) ? (int) $info['aof_last_rewrite_time_sec'] : -1;

        return [
            'reachable' => true,
            'aof_enabled' => isset($info['aof_enabled']) ? ((int) $info['aof_enabled']) === 1 : null,
            'aof_size_bytes' => isset($info['aof_current_size']) ? (int) $info['aof_current_size'] : null,
            'aof_last_rewrite_at' => $lastRewrite > 0 ? CarbonImmutable::now()->subSeconds($lastRewrite) : null,
            'rdb_last_save_at' => $lastSave > 0 ? CarbonImmutable::createFromTimestamp($lastSave) : null,
            'rdb_bgsave_in_progress' => isset($info['rdb_bgsave_in_progress']) ? ((int) $info['rdb_bgsave_in_progress']) === 1 : null,
            'save_schedule' => $schedule,
            'raw_save' => $rawSave,
        ];
    }

    /**
     * @return array{
     *     reachable: bool,
     *     aof_enabled: ?bool,
     *     aof_size_bytes: ?int,
     *     aof_last_rewrite_at: ?CarbonImmutable,
     *     rdb_last_save_at: ?CarbonImmutable,
     *     rdb_bgsave_in_progress: ?bool,
     *     save_schedule: list<array{seconds: int, changes: int}>,
     *     raw_save: ?string,
     * }
     */
    private function emptyState(): array
    {
        return [
            'reachable' => false,
            'aof_enabled' => null,
            'aof_size_bytes' => null,
            'aof_last_rewrite_at' => null,
            'rdb_last_save_at' => null,
            'rdb_bgsave_in_progress' => null,
            'save_schedule' => [],
            'raw_save' => null,
        ];
    }

    private function guardSupported(string $engine): void
    {
        if (! ServerCacheService::engineSupportsAuth($engine)) {
            throw new \InvalidArgumentException("Persistence ops are not supported for engine [{$engine}].");
        }
    }
}
