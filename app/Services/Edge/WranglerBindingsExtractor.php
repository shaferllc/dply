<?php

declare(strict_types=1);

namespace App\Services\Edge;

/**
 * Discovers binding declarations from `wrangler.toml` / `wrangler.jsonc`
 * in the build checkout so users with an existing Cloudflare Workers
 * project don't have to duplicate config into `dply.yaml`.
 *
 * Output shape matches `EdgeRepoConfig::bindings` exactly:
 *   ['kv' => ['MY_KV' => '<id>'], 'r2' => [...], 'd1' => [...], 'queues' => [...]]
 *
 * Returns name → value where "value" is whatever wrangler declared
 * (id, name, etc.) — the downstream {@see EdgeBindingsAutoResolver}
 * handles the lookup-or-create when values are titles rather than ids.
 *
 * Only top-level bindings are extracted (env-specific overrides like
 * `[env.production.kv_namespaces]` are intentionally ignored; dply
 * deploys to a single env per site).
 */
class WranglerBindingsExtractor
{
    /**
     * @return array<string, array<string, string>>
     */
    /** @return array<string, mixed> */
    public function extract(string $checkoutDir): array
    {
        foreach (['wrangler.jsonc', 'wrangler.json', 'wrangler.toml'] as $file) {
            $path = rtrim($checkoutDir, '/').'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            $parsed = $this->parse($path);
            if ($parsed === null) {
                continue;
            }

            return $this->normalize($parsed);
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (str_ends_with($path, '.toml')) {
            return $this->parseToml($raw);
        }

        // JSONC: strip // line comments + /* block */ comments before
        // json_decode. Wrangler accepts trailing commas too — drop those.
        $stripped = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $raw) ?? $raw;
        $stripped = (string) preg_replace('/,(\s*[}\]])/', '$1', $stripped);
        try {
            $parsed = json_decode($stripped, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Minimal TOML parser for the bindings we care about. wrangler's
     * `kv_namespaces`, `r2_buckets`, `d1_databases`, `queues.producers`
     * use array-of-tables syntax (`[[table]]`); we just need to harvest
     * the fields we care about, not full TOML compliance.
     *
     * @return array<string, mixed>
     */
    private function parseToml(string $raw): array
    {
        $out = [
            'kv_namespaces' => [],
            'r2_buckets' => [],
            'd1_databases' => [],
            'queues' => ['producers' => []],
        ];

        $section = null;
        $current = null;
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^\[\[([A-Za-z0-9_\.]+)\]\]$/', $trimmed, $m)) {
                $section = $m[1];
                $current = [];
                $this->pushTomlEntry($out, $section, $current);

                continue;
            }
            if (preg_match('/^\[([A-Za-z0-9_\.]+)\]$/', $trimmed, $m)) {
                $section = $m[1];
                $current = null;

                continue;
            }
            if ($current !== null && preg_match('/^([a-zA-Z0-9_]+)\s*=\s*(.+?)\s*$/', $trimmed, $m)) {
                $key = $m[1];
                $value = $this->coerceTomlScalar($m[2]);
                $current[$key] = $value;
                $this->updateTomlEntry($out, $section ?? '', $current);
            }
        }

        return $out;
    }

    private function coerceTomlScalar(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }
        if (preg_match('/^"(.*)"$/', $raw, $m) || preg_match("/^'(.*)'$/", $raw, $m)) {
            return $m[1];
        }
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }

    /** @param  array<string, mixed> $out */
    private function pushTomlEntry(array &$out, string $section, array $entry): void
    {
        match ($section) {
            'kv_namespaces' => $out['kv_namespaces'][] = $entry,
            'r2_buckets' => $out['r2_buckets'][] = $entry,
            'd1_databases' => $out['d1_databases'][] = $entry,
            'queues.producers' => $out['queues']['producers'][] = $entry,
            default => null,
        };
    }

    /** @param  array<string, mixed> $out */
    private function updateTomlEntry(array &$out, string $section, array $entry): void
    {
        $list = match ($section) {
            'kv_namespaces' => 'kv_namespaces',
            'r2_buckets' => 'r2_buckets',
            'd1_databases' => 'd1_databases',
            'queues.producers' => 'queues.producers',
            default => null,
        };
        if ($list === null) {
            return;
        }
        if ($list === 'queues.producers') {
            $count = count($out['queues']['producers']);
            if ($count > 0) {
                $out['queues']['producers'][$count - 1] = $entry;
            }

            return;
        }
        $count = count($out[$list]);
        if ($count > 0) {
            $out[$list][$count - 1] = $entry;
        }
    }

    /**
     * @param  array<string, mixed> $parsed
     * @param  array<string, mixed> $entry
     * @return array<string, array<string, string>>
     */
    private function normalize(array $parsed): array
    {
        $out = ['kv' => [], 'r2' => [], 'd1' => [], 'queues' => []];

        foreach ((array) ($parsed['kv_namespaces'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $entry['binding'] ?? null;
            $value = $entry['id'] ?? $entry['preview_id'] ?? null;
            if (is_string($name) && $name !== '' && is_string($value) && $value !== '') {
                $out['kv'][$name] = $value;
            }
        }

        foreach ((array) ($parsed['r2_buckets'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $entry['binding'] ?? null;
            $bucket = $entry['bucket_name'] ?? null;
            if (is_string($name) && $name !== '' && is_string($bucket) && $bucket !== '') {
                $out['r2'][$name] = $bucket;
            }
        }

        foreach ((array) ($parsed['d1_databases'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $entry['binding'] ?? null;
            $value = $entry['database_id'] ?? $entry['database_name'] ?? null;
            if (is_string($name) && $name !== '' && is_string($value) && $value !== '') {
                $out['d1'][$name] = $value;
            }
        }

        $producers = is_array($parsed['queues']['producers'] ?? null) ? $parsed['queues']['producers'] : [];
        foreach ($producers as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $entry['binding'] ?? null;
            $queue = $entry['queue'] ?? null;
            if (is_string($name) && $name !== '' && is_string($queue) && $queue !== '') {
                $out['queues'][$name] = $queue;
            }
        }

        return array_filter($out, static fn (array $b): bool => $b !== []);
    }
}
