<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Reads / clears the redis-family slowlog. Mirrors {@see CacheServiceStats} caching shape
 * so wire:poll cycles don't hammer the engine — slowlog entries are stable for the few
 * seconds between auto-refreshes.
 *
 * Slowlog records every command whose execution exceeded
 * `CONFIG GET slowlog-log-slower-than` microseconds (default 10000 = 10ms). Returns up
 * to 32 most-recent entries via `SLOWLOG GET 32`. Empty result means no slow commands in
 * the engine's bounded ring buffer — operationally the happy case.
 *
 * Memcached has no slowlog equivalent; callers guard via {@see ServerCacheService::engineSupportsAuth()}.
 */
class CacheServiceSlowlog
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * @return list<array{id: int, at: CarbonImmutable, duration_us: int, command: string, client_addr: string, client_name: string}>
     */
    public function entries(Server $server, ServerCacheService $cacheService): array
    {
        if (! ServerCacheService::engineSupportsAuth($cacheService->engine)) {
            return [];
        }

        $ttl = max(0, (int) config('server_cache.slowlog_cache_ttl_seconds', 5));
        $key = 'server.'.$server->id.'.cache_slowlog_v1.'.$cacheService->engine;

        $compute = function () use ($server, $cacheService): array {
            $raw = $this->fetchRaw($server, $cacheService);
            if ($raw === null) {
                return [];
            }

            return $this->parse($raw);
        };

        // Fail open if Cache backend is unhealthy (common when the operator's app
        // CACHE_STORE points at this managed Redis box and that box is the one
        // being diagnosed). Direct compute, no caching.
        try {
            return $ttl === 0 ? $compute() : Cache::remember($key, $ttl, $compute);
        } catch (\Throwable) {
            return $compute();
        }
    }

    /**
     * Reset (clear) the slowlog ring buffer on the engine. Returns true on success.
     */
    public function reset(Server $server, ServerCacheService $cacheService): bool
    {
        if (! ServerCacheService::engineSupportsAuth($cacheService->engine)) {
            return false;
        }

        $cli = CacheServiceStats::binaryFor($cacheService->engine);
        $authFlag = filled($cacheService->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $cacheService->auth_password).' '
            : '';

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'cache-service:slowlog-reset:'.$cacheService->engine,
                $authFlag.escapeshellarg($cli).' -p '.(int) $cacheService->port.' SLOWLOG RESET 2>/dev/null',
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

    public function forget(Server $server, string $engine): void
    {
        Cache::forget('server.'.$server->id.'.cache_slowlog_v1.'.$engine);
    }

    private function fetchRaw(Server $server, ServerCacheService $cacheService): ?string
    {
        $cli = CacheServiceStats::binaryFor($cacheService->engine);
        $authFlag = filled($cacheService->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $cacheService->auth_password).' '
            : '';

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'cache-service:slowlog-get:'.$cacheService->engine,
                $authFlag.escapeshellarg($cli).' -p '.(int) $cacheService->port.' SLOWLOG GET 32 2>/dev/null',
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
     * Parse redis-cli's default human-readable SLOWLOG GET output, which looks like:
     *
     *   1) 1) (integer) 14
     *      2) (integer) 1709316823
     *      3) (integer) 11583
     *      4) 1) "SET"
     *         2) "foo"
     *         3) "bar"
     *      5) "127.0.0.1:55012"
     *      6) "client-name"
     *
     * Items 1-4 are always present; items 5-6 are present on Redis 4+. We parse defensively
     * — a malformed entry is skipped rather than aborting the whole list.
     *
     * @return list<array{id: int, at: CarbonImmutable, duration_us: int, command: string, client_addr: string, client_name: string}>
     */
    private function parse(string $raw): array
    {
        $lines = preg_split('/\r?\n/', trim($raw)) ?: [];
        if ($lines === []) {
            return [];
        }

        $entries = [];
        $current = null;
        $argMode = false;
        $argLines = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '') {
                continue;
            }

            // Top-level entry marker: "N) 1) (integer) <id>" — start a fresh struct on
            // any line that begins with "1) (integer)" at the inner indentation level.
            if (preg_match('/^1\) \(integer\) (\d+)$/', $trimmed, $m)) {
                if ($current !== null) {
                    $current['command'] = trim(implode(' ', $argLines));
                    $entries[] = $current;
                }
                $current = [
                    'id' => (int) $m[1],
                    'at' => CarbonImmutable::now(),
                    'duration_us' => 0,
                    'command' => '',
                    'client_addr' => '',
                    'client_name' => '',
                ];
                $argMode = false;
                $argLines = [];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^2\) \(integer\) (\d+)$/', $trimmed, $m)) {
                $current['at'] = CarbonImmutable::createFromTimestamp((int) $m[1]);
                $argMode = false;

                continue;
            }
            if (preg_match('/^3\) \(integer\) (\d+)$/', $trimmed, $m)) {
                $current['duration_us'] = (int) $m[1];
                $argMode = false;

                continue;
            }
            if (str_starts_with($trimmed, '4) 1) ')) {
                $argMode = true;
                $argLines = [trim((string) preg_replace('/^4\) 1\) /', '', $trimmed), '"')];

                continue;
            }
            if ($argMode && preg_match('/^\d+\) "(.*)"$/', $trimmed, $m)) {
                $argLines[] = $m[1];

                continue;
            }
            if (preg_match('/^5\) "(.*)"$/', $trimmed, $m)) {
                $current['command'] = trim(implode(' ', $argLines));
                $argMode = false;
                $argLines = [];
                $current['client_addr'] = $m[1];

                continue;
            }
            if (preg_match('/^6\) "(.*)"$/', $trimmed, $m)) {
                $current['client_name'] = $m[1];
                $argMode = false;
            }
        }

        if ($current !== null) {
            if ($current['command'] === '' && $argLines !== []) {
                $current['command'] = trim(implode(' ', $argLines));
            }
            $entries[] = $current;
        }

        return $entries;
    }
}
