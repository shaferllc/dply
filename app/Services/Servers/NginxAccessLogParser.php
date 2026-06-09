<?php

namespace App\Services\Servers;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses an nginx "combined" access-log block into structured rows.
 *
 * The default nginx log_format combined is:
 *   $remote_addr - $remote_user [$time_local] "$request" $status
 *   $body_bytes_sent "$http_referer" "$http_user_agent"
 *
 * where $request is "$method $uri $protocol". This parser is deliberately
 * tolerant: any line that does not match the expected shape is returned as a
 * raw passthrough row rather than throwing. That lets the viewer render a mix
 * of parsed entries and the odd unparseable line without losing data.
 */
class NginxAccessLogParser
{
    /**
     * The combined format, captured group by group. $remote_user, referer and
     * user-agent may legitimately be "-" (logged as a literal dash). The
     * request target may be a single token (e.g. a bad request that nginx
     * could not split into method/uri/protocol), so we capture it whole and
     * split it afterwards.
     */
    private const COMBINED = '/^(?<ip>\S+)\s+'
        .'(?<ident>\S+)\s+'
        .'(?<user>\S+)\s+'
        .'\[(?<time>[^\]]+)\]\s+'
        .'"(?<request>(?:[^"\\\\]|\\\\.)*)"\s+'
        .'(?<status>\d{3})\s+'
        .'(?<bytes>\d+|-)'
        .'(?:\s+"(?<referer>(?:[^"\\\\]|\\\\.)*)"\s+"(?<agent>(?:[^"\\\\]|\\\\.)*)")?'
        .'\s*$/';

    /**
     * Parse a raw log block (newline-delimited) into structured rows.
     *
     * Each returned row is an associative array. Parsed rows carry
     * 'parsed' => true and the structured fields; unparseable rows carry
     * 'parsed' => false and the original line under 'raw'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $raw): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = $this->parseLine($line);
        }

        return $rows;
    }

    /**
     * Parse a single line. Never throws — falls back to a raw row.
     *
     * @return array<string, mixed>
     */
    public function parseLine(string $line): array
    {
        if (! preg_match(self::COMBINED, trim($line), $m)) {
            return $this->rawRow($line);
        }

        [$method, $path, $protocol] = $this->splitRequest($m['request'] ?? '');

        return [
            'parsed' => true,
            'raw' => $line,
            'ip' => $this->dashToNull($m['ip'] ?? null),
            'user' => $this->dashToNull($m['user'] ?? null),
            'time' => $this->parseTime($m['time'] ?? ''),
            'time_raw' => $m['time'] ?? null,
            'method' => $method,
            'path' => $path,
            'protocol' => $protocol,
            'status' => isset($m['status']) ? (int) $m['status'] : null,
            'bytes' => $this->parseBytes($m['bytes'] ?? null),
            'referer' => $this->dashToNull($m['referer'] ?? null),
            'user_agent' => $this->dashToNull($m['agent'] ?? null),
        ];
    }

    /**
     * Quick test: does this block look like combined-format access log? Used
     * to decide whether the structured viewer should engage at all. We sample
     * the non-empty lines and require a majority to parse cleanly so that a
     * stray "[error] ..." or journal-style block falls back to raw display.
     */
    public function looksLikeCombined(string $raw): bool
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $raw) ?: [],
            fn ($l) => trim($l) !== '',
        ));

        $sample = array_slice($lines, 0, 25);
        if ($sample === []) {
            return false;
        }

        $matched = 0;
        foreach ($sample as $line) {
            if (preg_match(self::COMBINED, trim($line))) {
                $matched++;
            }
        }

        return $matched >= (int) ceil(count($sample) / 2);
    }

    /**
     * Summarise a set of parsed rows for the viewer header: total requests,
     * counts by status class, and the top paths.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summarize(array $rows): array
    {
        $parsed = array_filter($rows, fn ($r) => ($r['parsed'] ?? false) === true);

        $classes = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $paths = [];

        foreach ($parsed as $r) {
            $status = $r['status'] ?? null;
            $classes[$this->statusClass($status)] = ($classes[$this->statusClass($status)] ?? 0) + 1;

            $path = $r['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[$path] = ($paths[$path] ?? 0) + 1;
            }
        }

        arsort($paths);
        $topPaths = array_slice(
            array_map(fn ($path, $count) => ['path' => $path, 'count' => $count], array_keys($paths), array_values($paths)),
            0,
            3,
        );

        return [
            'total' => count($parsed),
            'classes' => $classes,
            'top_paths' => $topPaths,
        ];
    }

    /**
     * Bucket an HTTP status into a class key used for colour-coding.
     */
    public function statusClass(?int $status): string
    {
        return match (true) {
            $status === null => 'other',
            $status >= 200 && $status < 300 => '2xx',
            $status >= 300 && $status < 400 => '3xx',
            $status >= 400 && $status < 500 => '4xx',
            $status >= 500 && $status < 600 => '5xx',
            default => 'other',
        };
    }

    /**
     * Split "$method $uri $protocol" into its three parts. A malformed request
     * (single token, or empty) keeps whatever we have under path so nothing is
     * silently dropped.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function splitRequest(string $request): array
    {
        $request = $this->unescape(trim($request));

        if ($request === '' || $request === '-') {
            return [null, null, null];
        }

        $parts = preg_split('/\s+/', $request, 3) ?: [];

        if (count($parts) === 3 && $this->looksLikeMethod($parts[0])) {
            return [$parts[0], $parts[1], $parts[2]];
        }

        // Fall back: couldn't confidently split — surface the whole request as
        // the path so the operator still sees what was requested.
        return [null, $request, null];
    }

    private function looksLikeMethod(string $token): bool
    {
        return (bool) preg_match('/^[A-Z]{3,10}$/', $token);
    }

    private function parseTime(string $raw): ?CarbonImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // nginx $time_local: 10/Oct/2000:13:55:36 -0700
        try {
            return CarbonImmutable::createFromFormat('d/M/Y:H:i:s O', $raw) ?: null;
        } catch (Throwable) {
            // fall through
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseBytes(?string $bytes): ?int
    {
        if ($bytes === null || $bytes === '-' || $bytes === '') {
            return null;
        }

        return (int) $bytes;
    }

    private function dashToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->unescape($value);

        return ($value === '' || $value === '-') ? null : $value;
    }

    /**
     * nginx escapes embedded quotes/backslashes in quoted fields. Undo the
     * common escapes so referers and user-agents read naturally.
     */
    private function unescape(string $value): string
    {
        return str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRow(string $line): array
    {
        return [
            'parsed' => false,
            'raw' => rtrim($line, "\r\n"),
        ];
    }
}
