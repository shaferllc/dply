<?php

declare(strict_types=1);

namespace App\Services\Edge\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses an in-repo dply config file (YAML or JSON) into an
 * {@see EdgeRepoConfig} snapshot the build runner stores on the
 * deployment row.
 *
 * Schema (YAML form):
 *
 *   build:
 *     command: npm run build
 *     output: dist
 *     root: apps/web        # monorepo root, relative to checkout
 *     node: "20"
 *
 *   redirects:
 *     - from: /old/*
 *       to: /new/:splat
 *       status: 301
 *
 *   rewrites:
 *     - from: /api/*
 *       to: https://api.example.com/:splat
 *
 *   headers:
 *     - for: /static/*
 *       values:
 *         Cache-Control: "public, max-age=31536000, immutable"
 *
 * All sections are optional. Malformed rules are dropped with a
 * warning recorded in the returned config — surfaced in the build log
 * and on the deploy detail page so the user sees what was ignored.
 */
class EdgeRepoConfigLoader
{
    /** Files searched in priority order at the checkout root. */
    private const CANDIDATE_FILES = [
        'dply.yaml',
        'dply.yml',
        'dply.json',
    ];

    /** Hard cap so a hostile repo can't ship a huge config that blows up KV. */
    private const MAX_FILE_BYTES = 64 * 1024;

    private const ALLOWED_STATUS_CODES = [301, 302, 303, 307, 308];

    public function loadFromDirectory(string $checkoutPath): ?EdgeRepoConfig
    {
        $base = rtrim($checkoutPath, '/');
        if ($base === '' || ! is_dir($base)) {
            return null;
        }

        foreach (self::CANDIDATE_FILES as $candidate) {
            $path = $base.'/'.$candidate;
            if (! is_file($path)) {
                continue;
            }
            if (filesize($path) > self::MAX_FILE_BYTES) {
                return new EdgeRepoConfig(
                    sourcePath: $candidate,
                    warnings: [sprintf('%s exceeds the %d KB limit and was ignored.', $candidate, self::MAX_FILE_BYTES / 1024)],
                );
            }

            $raw = (string) file_get_contents($path);

            return $this->parse($candidate, $raw);
        }

        return null;
    }

    /**
     * @internal exposed for testing — callers should use {@see loadFromDirectory()}.
     */
    public function parse(string $sourcePath, string $raw): EdgeRepoConfig
    {
        $warnings = [];
        $parsed = $this->decode($sourcePath, $raw, $warnings);
        if (! is_array($parsed)) {
            return new EdgeRepoConfig(
                sourcePath: $sourcePath,
                warnings: $warnings === [] ? ['Config file could not be parsed.'] : $warnings,
            );
        }

        return new EdgeRepoConfig(
            sourcePath: $sourcePath,
            build: $this->normalizeBuild($parsed['build'] ?? null, $warnings),
            envFiles: $this->normalizeEnvFiles($parsed['build'] ?? null, $warnings),
            redirects: $this->normalizeRedirects($parsed['redirects'] ?? null, $warnings),
            rewrites: $this->normalizeRewrites($parsed['rewrites'] ?? null, $warnings),
            headers: $this->normalizeHeaders($parsed['headers'] ?? null, $warnings),
            bindings: $this->normalizeBindings($parsed['bindings'] ?? null, $warnings),
            crons: $this->normalizeCrons($parsed['crons'] ?? null, $warnings),
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $warnings
     */
    private function decode(string $sourcePath, string $raw, array &$warnings): mixed
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        try {
            if ($extension === 'json') {
                return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            }

            return Yaml::parse($raw);
        } catch (\Throwable $e) {
            $warnings[] = sprintf('%s parse error: %s', $sourcePath, $e->getMessage());

            return null;
        }
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, string>
     */
    private function normalizeBuild(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $build = [];
        foreach (['command', 'output', 'root', 'node'] as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }
            $entry = $value[$key];
            if (! is_string($entry) && ! is_int($entry)) {
                $warnings[] = sprintf('build.%s must be a string — ignored.', $key);

                continue;
            }
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $build[$key] = $entry;
        }

        if (isset($build['root']) && str_contains($build['root'], '..')) {
            $warnings[] = 'build.root may not contain "..".';
            unset($build['root']);
        }

        return $build;
    }

    /**
     * Parses `build.env_files: [".env.production", ...]` — paths
     * relative to the checkout root that the build runner loads + merges
     * into the env handed to Docker. Dashboard env vars win on conflict
     * (handled by the runner, not here).
     *
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function normalizeEnvFiles(mixed $value, array &$warnings): array
    {
        if (! is_array($value) || ! isset($value['env_files'])) {
            return [];
        }
        $raw = $value['env_files'];
        if (! is_array($raw)) {
            $warnings[] = 'build.env_files must be a list of repo-relative paths.';

            return [];
        }

        $out = [];
        foreach ($raw as $index => $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                $warnings[] = sprintf('build.env_files[%d] must be a non-empty string.', $index);

                continue;
            }
            $path = trim($entry);
            if (str_contains($path, '..') || str_starts_with($path, '/')) {
                $warnings[] = sprintf('build.env_files[%d] %s — must be a repo-relative path (no ".." or leading "/").', $index, $path);

                continue;
            }
            $out[] = $path;
        }

        return $out;
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{from: string, to: string, status: int}>
     */
    private function normalizeRedirects(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('redirects[%d] must be a map.', $index);

                continue;
            }
            $from = isset($entry['from']) && is_string($entry['from']) ? trim($entry['from']) : '';
            $to = isset($entry['to']) && is_string($entry['to']) ? trim($entry['to']) : '';
            $status = isset($entry['status']) ? (int) $entry['status'] : 301;
            if ($from === '' || $to === '') {
                $warnings[] = sprintf('redirects[%d] missing required `from`/`to`.', $index);

                continue;
            }
            if (! in_array($status, self::ALLOWED_STATUS_CODES, true)) {
                $warnings[] = sprintf('redirects[%d] status %d not in {301,302,303,307,308}; defaulting to 301.', $index, $status);
                $status = 301;
            }
            $out[] = ['from' => $from, 'to' => $to, 'status' => $status];
        }

        return $out;
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{from: string, to: string}>
     */
    private function normalizeRewrites(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('rewrites[%d] must be a map.', $index);

                continue;
            }
            $from = isset($entry['from']) && is_string($entry['from']) ? trim($entry['from']) : '';
            $to = isset($entry['to']) && is_string($entry['to']) ? trim($entry['to']) : '';
            if ($from === '' || $to === '') {
                $warnings[] = sprintf('rewrites[%d] missing required `from`/`to`.', $index);

                continue;
            }
            $out[] = ['from' => $from, 'to' => $to];
        }

        return $out;
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{for: string, values: array<string, string>}>
     */
    private function normalizeHeaders(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = sprintf('headers[%d] must be a map.', $index);

                continue;
            }
            $for = isset($entry['for']) && is_string($entry['for']) ? trim($entry['for']) : '';
            $values = $entry['values'] ?? null;
            if ($for === '' || ! is_array($values) || $values === []) {
                $warnings[] = sprintf('headers[%d] requires `for` and a non-empty `values` map.', $index);

                continue;
            }

            $cleanValues = [];
            foreach ($values as $name => $headerValue) {
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }
                if (! is_string($headerValue) && ! is_int($headerValue)) {
                    continue;
                }
                $cleanValues[trim($name)] = trim((string) $headerValue);
            }

            if ($cleanValues === []) {
                $warnings[] = sprintf('headers[%d] has no valid name/value pairs.', $index);

                continue;
            }

            $out[] = ['for' => $for, 'values' => $cleanValues];
        }

        return $out;
    }

    /**
     * Bindings declarations for per-deployment Worker scripts
     * (middleware + SSR). Schema:
     *
     *   bindings:
     *     kv:
     *       SESSIONS: namespace_id_here
     *     r2:
     *       UPLOADS: bucket-name
     *     d1:
     *       MAIN_DB: database_id
     *     queues:
     *       JOBS: queue_name
     *
     * Names must be ALL_CAPS_WITH_UNDERSCORES (Worker binding
     * convention). Values vary per backend — KV / D1 are CF resource
     * UUIDs; R2 / queues are bucket / queue names.
     *
     * @param  list<string>  $warnings
     * @return array{kv?: array<string, string>, r2?: array<string, string>, d1?: array<string, string>, queues?: array<string, string>}
     */
    private function normalizeBindings(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (['kv', 'r2', 'd1', 'queues'] as $kind) {
            $bucket = $value[$kind] ?? null;
            if (! is_array($bucket) || $bucket === []) {
                continue;
            }
            $clean = [];
            foreach ($bucket as $name => $target) {
                if (! is_string($name) || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $name) !== 1) {
                    $warnings[] = sprintf('bindings.%s.%s — names must be ALL_CAPS_WITH_UNDERSCORES.', $kind, (string) $name);

                    continue;
                }
                if (! is_string($target) || trim($target) === '') {
                    $warnings[] = sprintf('bindings.%s.%s — value must be a non-empty string.', $kind, $name);

                    continue;
                }
                $clean[$name] = trim($target);
            }
            if ($clean !== []) {
                $out[$kind] = $clean;
            }
        }

        return $out;
    }

    /**
     * Cron triggers attached to the per-deployment middleware / SSR
     * Worker. Schema:
     *
     *   crons:
     *     - schedule: "0 * * * *"
     *       handler: src/cron/hourly.ts  # optional, v1 ignored
     *
     * Shape-only cron validation (5 whitespace-separated tokens of
     * cron-legal characters) — Cloudflare is the source of truth for
     * semantics. Max 5 schedules per site (CF limit).
     *
     * @param  list<string>  $warnings
     * @return list<array{schedule: string, handler?: string}>
     */
    private function normalizeCrons(mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (count($out) >= 5) {
                $warnings[] = 'crons[]: dply Edge supports up to 5 schedules per site — extras ignored.';
                break;
            }
            if (! is_array($entry)) {
                $warnings[] = sprintf('crons[%d] must be a map.', $index);

                continue;
            }
            $schedule = isset($entry['schedule']) && is_string($entry['schedule']) ? trim($entry['schedule']) : '';
            if ($schedule === '' || ! $this->looksLikeCronExpression($schedule)) {
                $warnings[] = sprintf('crons[%d] missing or invalid `schedule` (expected 5-field cron).', $index);

                continue;
            }

            $normalized = ['schedule' => $schedule];
            $handler = isset($entry['handler']) && is_string($entry['handler']) ? trim($entry['handler']) : '';
            if ($handler !== '') {
                // v1 contract is "export `scheduled` from your middleware
                // module"; standalone handler files are deferred. Surface
                // the warning so users know their handler field is moot.
                $warnings[] = sprintf('crons[%d].handler is ignored in v1 — export `scheduled` from your middleware module instead.', $index);
                $normalized['handler'] = $handler;
            }

            $out[] = $normalized;
        }

        return $out;
    }

    private function looksLikeCronExpression(string $value): bool
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if (count($parts) !== 5) {
            return false;
        }

        foreach ($parts as $part) {
            if (preg_match('#^[0-9*/,\-A-Z]+$#i', $part) !== 1) {
                return false;
            }
        }

        return true;
    }
}
