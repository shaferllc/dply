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
     * Tokens that redis-cli `--no-raw` uses to label empty / nil responses.
     * These come back as if they were keys when SCAN finds nothing matching;
     * we filter them so the browser doesn't show "(empty array)" as a key.
     */
    private static function isEmptyMarker(string $token): bool
    {
        return in_array(strtolower(trim($token)), [
            '(empty array)',
            '(empty list or set)',
            '(empty hash)',
            '(nil)',
        ], true);
    }

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
        // AUTH flag must come AFTER the cli binary — `-a 'pw' valkey-cli …` makes
        // bash run `-a` as the command (which fails with "-a: command not found"
        // AND visibly leaks the password). `--no-auth-warning` suppresses the
        // "using -a on the command line may not be safe" stderr line that would
        // otherwise glue onto SCAN/GET output via 2>&1.
        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' --no-auth-warning '
            : '';

        $keys = [];
        $current = $cursor;
        $iterations = 0;

        // Walk a small number of iterations server-side. The shell loop keeps
        // us in one SSH round-trip per scan() call instead of N.
        //
        // Use a nowdoc (`<<<'BASH'`) so PHP does NOT interpolate the bash variables ($CURSOR,
        // $OUT) — they need to stay literal for bash to evaluate. The previous `."..."` chain
        // mixed double-quoted PHP segments and made PHP try to expand $CURSOR/$OUT, which under
        // PHP 8 throws "Undefined variable" warnings that bubbled up as the SCAN error message.
        // `--raw` strips the pretty redis-cli wrapper (`1) "key_name"`) so each
        // line of OUT is exactly:
        //   <cursor>
        //   <key1>
        //   <key2>
        //   …
        // With `--no-raw` (the previous default), every line came back quoted
        // and prefixed with the array index — operators saw keys like
        // `1) "dply_horizon:recent_jobs"` in the table and `TYPE` of that
        // literal returned `none`, which is the exact symptom this fix
        // addresses.
        $template = <<<'BASH'
CURSOR=%s
for i in $(seq 1 %d); do
    OUT=$(%s %s-p %d --raw SCAN "$CURSOR" MATCH %s COUNT %d 2>&1) || { echo "__DPLY_SCAN_ERR__$OUT"; exit 1; }
    CURSOR=$(echo "$OUT" | head -n 1)
    echo "$OUT" | tail -n +2
    if [ "$CURSOR" = "0" ]; then break; fi
done
echo "__DPLY_CURSOR__$CURSOR"
BASH;
        $script = sprintf(
            $template,
            escapeshellarg($current),
            self::MAX_SCAN_ITERATIONS,
            escapeshellarg($cli),
            $authFlag,
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
            // With --raw, each key is emitted as a bare line (no `N) "..."`
            // wrapper). Use the line as the key. We still tolerate the old
            // --no-raw shape in case any caller passes a script that doesn't
            // include --raw.
            $captured = $trim;
            if (preg_match('/^\d+\)\s*"(.*)"\s*$/', $trim, $m) === 1) {
                $captured = $m[1];
            } elseif (preg_match('/^\d+\)\s*(.*)$/', $trim, $m) === 1) {
                $captured = $m[1];
            }

            // redis-cli emits human-friendly markers for empty results / nil
            // values (`(empty array)`, `(empty list or set)`, `(nil)`); drop
            // them so they don't show up as bogus keys in the browser.
            if (! self::isEmptyMarker($captured)) {
                $keys[] = $captured;
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
        // AUTH flag must come AFTER the cli binary — `-a 'pw' valkey-cli …` makes
        // bash run `-a` as the command (which fails with "-a: command not found"
        // AND visibly leaks the password). `--no-auth-warning` suppresses the
        // "using -a on the command line may not be safe" stderr line that would
        // otherwise glue onto SCAN/GET output via 2>&1.
        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' --no-auth-warning '
            : '';

        // `--raw` so TTL comes back as a plain number (`5`) instead of
        // `(integer) 5` — the latter would `(int)`-cast to 0. List/hash/set
        // outputs also come back one item per line, no `N) "…"` wrapper, so
        // explode("\n") gives clean tokens for the value renderer.
        $cliCmd = sprintf('%s %s-p %d --raw', escapeshellarg($cli), $authFlag, (int) $row->port);

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
