<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Paginated SCAN-based key explorer for the workspace's Stats sub-tab. Uses
 * `redis-cli SCAN <cursor> MATCH <pattern> COUNT <n>` so we don't lock the
 * engine the way `KEYS *` does on a hot cache. The cursor is opaque to the
 * caller — pass `'0'` to start a fresh scan, then keep passing whatever
 * cursor came back until the explorer reports completion.
 *
 * Per-key inspection runs `TYPE` first, then routes to the right value-getter
 * (`GET` / `LRANGE` / `HGETALL` / `SMEMBERS` / `ZRANGE WITHSCORES`). Values
 * are truncated server-side at 8 KB so a single huge entry can't lock the
 * Livewire roundtrip.
 *
 * Memcached is not supported; SCAN has no equivalent in the binary protocol.
 * Callers must check `ServerCacheService::engineSupportsAuth()` first.
 */
class CacheServiceKeyExplorer
{
    /**
     * Soft cap on the number of SCAN iterations a single `scan()` call will
     * walk before returning what it has. Without this, a tiny COUNT plus a
     * large keyspace would loop a long time before yielding control to the
     * UI. Operators get a "Load more" button that resumes from the returned
     * cursor.
     */
    public const MAX_SCAN_ITERATIONS = 5;

    /**
     * Single-key value display cap (bytes). Larger values get truncated with
     * a marker. Keeps the Livewire payload bounded even when an operator
     * inspects a 10 MB JSON blob.
     */
    public const MAX_VALUE_BYTES = 8192;

    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * Walk SCAN for up to `MAX_SCAN_ITERATIONS` iterations and return the
     * accumulated keys + the next cursor. The cursor is `'0'` once the scan
     * has completed; any other value means there's more to fetch.
     *
     * @return array{cursor: string, keys: list<string>, complete: bool}
     */
    public function scan(
        Server $server,
        ServerCacheService $row,
        string $cursor = '0',
        string $pattern = '*',
        int $count = 200,
    ): array {
        $this->guard($row);

        $cli = CacheServiceStats::binaryFor($row->engine);
        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' '
            : '';

        $keys = [];
        $current = $cursor;
        $iterations = 0;

        // Walk a small number of iterations server-side. The shell loop keeps
        // us in one SSH round-trip per scan() call instead of N.
        $script = sprintf(
            'CURSOR=%s; for i in $(seq 1 %d); do '
            ."OUT=$(%s%s -p %d --no-raw SCAN \"$CURSOR\" MATCH %s COUNT %d 2>&1) || { echo \"__DPLY_SCAN_ERR__$OUT\"; exit 1; }; "
            ."CURSOR=$(echo \"$OUT\" | head -n 1); "
            ."echo \"$OUT\" | tail -n +2; "
            ."if [ \"$CURSOR\" = \"0\" ]; then break; fi; "
            .'done; '
            ."echo \"__DPLY_CURSOR__$CURSOR\"",
            escapeshellarg($current),
            self::MAX_SCAN_ITERATIONS,
            $authFlag,
            escapeshellarg($cli),
            (int) $row->port,
            escapeshellarg($pattern),
            max(1, $count),
        );

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'cache-service:scan:'.$row->engine,
                $script,
                timeoutSeconds: 30,
                asRoot: false,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('SCAN failed: '.$e->getMessage(), previous: $e);
        }

        $nextCursor = '0';
        foreach (explode("\n", $output->buffer) as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (str_starts_with($trim, '__DPLY_SCAN_ERR__')) {
                throw new \RuntimeException('SCAN error: '.substr($trim, strlen('__DPLY_SCAN_ERR__')));
            }
            if (str_starts_with($trim, '__DPLY_CURSOR__')) {
                $nextCursor = substr($trim, strlen('__DPLY_CURSOR__'));

                continue;
            }
            // SCAN's --no-raw output for keys looks like `1) "foo"` / `2) "bar"`;
            // we strip the `N) ` prefix and the surrounding quotes.
            if (preg_match('/^\d+\)\s*"(.*)"\s*$/', $trim, $m) === 1) {
                $keys[] = $m[1];
            } elseif (preg_match('/^\d+\)\s*(.*)$/', $trim, $m) === 1) {
                // Fallback when --no-raw emitted an unquoted token (e.g. an
                // integer-shaped key); take it as-is.
                $keys[] = $m[1];
            }
        }

        return [
            'cursor' => $nextCursor,
            'keys' => $keys,
            'complete' => $nextCursor === '0',
        ];
    }

    /**
     * Inspect a single key. Runs `TYPE` then routes to the appropriate value
     * getter and `TTL`. The returned `value` is always a string for scalar
     * types and a list of strings for collection types.
     *
     * @return array{type: string, ttl: int, value: string|list<string>, truncated: bool}
     */
    public function inspect(Server $server, ServerCacheService $row, string $key): array
    {
        $this->guard($row);

        $cli = CacheServiceStats::binaryFor($row->engine);
        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' '
            : '';

        $cliCmd = sprintf('%s%s -p %d --no-raw', $authFlag, escapeshellarg($cli), (int) $row->port);

        // First call: TYPE + TTL. We do both in one SSH round to keep latency
        // down. The output is two lines — type, ttl.
        try {
            $meta = $this->executor->runInlineBash(
                $server,
                'cache-service:key-meta:'.$row->engine,
                $cliCmd.' TYPE '.escapeshellarg($key).'; '.$cliCmd.' TTL '.escapeshellarg($key),
                timeoutSeconds: 15,
                asRoot: false,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('Key inspection failed: '.$e->getMessage(), previous: $e);
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $meta->buffer)), fn ($l) => $l !== ''));
        $type = $lines[0] ?? 'none';
        $ttl = isset($lines[1]) ? (int) $lines[1] : -1;

        if ($type === 'none') {
            return ['type' => 'none', 'ttl' => -1, 'value' => '', 'truncated' => false];
        }

        // Pick the value-getter for the type. Each runs in its own SSH call
        // because TYPE-routing in shell is awkward and the output formats
        // differ enough that parsing them inline gets messy.
        $valueScript = match ($type) {
            'string' => $cliCmd.' GET '.escapeshellarg($key),
            'list' => $cliCmd.' LRANGE '.escapeshellarg($key).' 0 99',
            'hash' => $cliCmd.' HGETALL '.escapeshellarg($key),
            'set' => $cliCmd.' SMEMBERS '.escapeshellarg($key),
            'zset' => $cliCmd.' ZRANGE '.escapeshellarg($key).' 0 99 WITHSCORES',
            'stream' => $cliCmd.' XRANGE '.escapeshellarg($key).' - + COUNT 50',
            default => null,
        };

        if ($valueScript === null) {
            return [
                'type' => $type,
                'ttl' => $ttl,
                'value' => '['.__('Unsupported type').']',
                'truncated' => false,
            ];
        }

        try {
            $value = $this->executor->runInlineBash(
                $server,
                'cache-service:key-value:'.$row->engine,
                $valueScript,
                timeoutSeconds: 15,
                asRoot: false,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('Value fetch failed: '.$e->getMessage(), previous: $e);
        }

        $truncated = strlen($value->buffer) > self::MAX_VALUE_BYTES;
        $rendered = $truncated ? substr($value->buffer, 0, self::MAX_VALUE_BYTES) : $value->buffer;

        if ($type === 'string') {
            return [
                'type' => $type,
                'ttl' => $ttl,
                'value' => trim($rendered, "\"\n"),
                'truncated' => $truncated,
            ];
        }

        // Collection types: split on lines, drop empties, keep the order.
        $items = array_values(array_filter(
            array_map('trim', explode("\n", $rendered)),
            fn ($l) => $l !== '',
        ));

        return [
            'type' => $type,
            'ttl' => $ttl,
            'value' => $items,
            'truncated' => $truncated,
        ];
    }

    private function guard(ServerCacheService $row): void
    {
        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            throw new \InvalidArgumentException('Key explorer is not supported for '.$row->engine.'.');
        }
    }
}
