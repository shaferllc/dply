<?php

declare(strict_types=1);

namespace App\Services\Deploy\Manifest;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Yosymfony\Toml\Toml;

/**
 * Parses a `dply.yaml` manifest into a {@see DplyManifest} value object.
 *
 * The parser is strict about *typed* shape (string vs list vs map) but
 * permissive about unknown top-level keys (forward-compat: older parsers
 * read newer manifests and emit warnings rather than failing).
 *
 * Empty / missing fields are normalized to canonical empty values:
 *   - missing string → null
 *   - missing list → []
 *   - missing map → []
 *
 * String/list polymorphism is normalized at parse time: `build: "composer install"`
 * and `build: ["composer install"]` produce identical DTOs.
 */
class DplyManifestParser
{
    /** Manifest base names, in discovery precedence order. */
    public const FILE_NAMES = ['dply.yaml', 'dply.yml', 'dply.json', 'dply.toml'];

    public function parseFile(string $path): DplyManifest
    {
        if (! is_file($path)) {
            throw new DplyManifestException("dply manifest not found at: {$path}");
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new DplyManifestException("Could not read dply manifest at: {$path}");
        }

        return $this->parseRaw($contents, $path);
    }

    /**
     * Parse raw manifest contents, choosing the format from the source path's
     * extension (falls back to YAML, which also accepts JSON). Use this when the
     * bytes were fetched from a remote box (the file name is known but it's not
     * on the local disk).
     */
    public function parseRaw(string $raw, string $sourcePath): DplyManifest
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'json' => $this->parseJson($raw),
            'toml' => $this->parseToml($raw),
            default => $this->parseYaml($raw), // yaml/yml (YAML is a JSON superset)
        };
    }

    public function parseJson(string $json): DplyManifest
    {
        $trimmed = trim($json);
        if ($trimmed === '') {
            return DplyManifest::empty();
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw DplyManifestException::invalidYaml('invalid JSON: '.json_last_error_msg());
        }

        return $this->fromDecoded($data);
    }

    public function parseToml(string $toml): DplyManifest
    {
        $trimmed = trim($toml);
        if ($trimmed === '') {
            return DplyManifest::empty();
        }

        try {
            $data = Toml::parse($toml);
        } catch (\Throwable $e) {
            throw DplyManifestException::invalidYaml('invalid TOML: '.$e->getMessage(), $e);
        }

        return $this->fromDecoded($data);
    }

    public function parseYaml(string $yaml): DplyManifest
    {
        $trimmed = trim($yaml);
        if ($trimmed === '') {
            return DplyManifest::empty();
        }

        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw DplyManifestException::invalidYaml($e->getMessage(), $e);
        }

        return $this->fromDecoded($data);
    }

    /**
     * Validate a decoded payload (from any format) is a top-level map and parse
     * it. Null/empty → empty manifest; list/scalar → error.
     */
    private function fromDecoded(mixed $data): DplyManifest
    {
        if ($data === null) {
            return DplyManifest::empty();
        }

        if (! is_array($data) || array_is_list($data)) {
            throw DplyManifestException::invalidField(
                fieldPath: '(root)',
                detail: 'top-level manifest must be a map of keys to values, not a list or scalar',
            );
        }

        return $this->parseArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function parseArray(array $data): DplyManifest
    {
        return new DplyManifest(
            runtime: $this->parseRuntime($data['runtime'] ?? null),
            version: $this->parseVersion($data['version'] ?? null),
            build: $this->parseStringOrList('build', $data['build'] ?? null),
            release: $this->parseStringOrList('release', $data['release'] ?? null),
            processes: $this->parseProcesses($data['processes'] ?? null),
            warnings: $this->collectWarnings($data),
            healthcheck: $this->parseHealthcheck($data['healthcheck'] ?? null),
        );
    }

    private function parseHealthcheck(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Accept a bare path string (`healthcheck: /up`) or a map with a
        // `path` key (`healthcheck: { path: /up }`).
        if (is_array($value) && ! array_is_list($value)) {
            $value = $value['path'] ?? null;
        }

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw DplyManifestException::invalidField('healthcheck', 'must be a path string or a map with a `path`');
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseRuntime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw DplyManifestException::invalidField('runtime', 'must be a string');
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (! in_array($normalized, DplyManifest::ALLOWED_RUNTIMES, true)) {
            throw DplyManifestException::invalidField(
                fieldPath: 'runtime',
                detail: sprintf(
                    'must be one of [%s]; got %s',
                    implode(', ', DplyManifest::ALLOWED_RUNTIMES),
                    var_export($value, true),
                ),
            );
        }

        return $normalized;
    }

    private function parseVersion(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Accept ints and floats — users often write `version: 22` or `version: 8.3`
        // without quotes and YAML parses them as numbers. We coerce to string here so
        // downstream code (mise / runtime detection) sees a uniform shape.
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw DplyManifestException::invalidField('version', 'must be a string');
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function parseStringOrList(string $field, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? [] : [$trimmed];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw DplyManifestException::invalidField(
                fieldPath: $field,
                detail: 'must be a string or a list of strings',
            );
        }

        $result = [];
        foreach ($value as $i => $entry) {
            if (! is_string($entry)) {
                throw DplyManifestException::invalidField(
                    fieldPath: "{$field}.{$i}",
                    detail: 'must be a string',
                );
            }
            $trimmed = trim($entry);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    /**
     * @return array<string, DplyManifestProcess>
     */
    private function parseProcesses(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || array_is_list($value)) {
            throw DplyManifestException::invalidField(
                fieldPath: 'processes',
                detail: 'must be a map of process name → command (or {command, scale})',
            );
        }

        $result = [];
        foreach ($value as $name => $entry) {
            if (! is_string($name) || trim($name) === '') {
                throw DplyManifestException::invalidField(
                    fieldPath: 'processes',
                    detail: 'process names must be non-empty strings',
                );
            }

            $result[$name] = $this->parseSingleProcess($name, $entry);
        }

        return $result;
    }

    private function parseSingleProcess(string $name, mixed $entry): DplyManifestProcess
    {
        // Shorthand: `worker: bundle exec sidekiq` → command-only, scale=1.
        if (is_string($entry)) {
            $command = trim($entry);
            if ($command === '') {
                throw DplyManifestException::invalidField(
                    fieldPath: "processes.{$name}",
                    detail: 'command must be a non-empty string',
                );
            }

            return new DplyManifestProcess(name: $name, command: $command, scale: 1);
        }

        if (! is_array($entry) || array_is_list($entry)) {
            throw DplyManifestException::invalidField(
                fieldPath: "processes.{$name}",
                detail: 'must be a string command or a map with `command` and optional `scale`',
            );
        }

        $command = $entry['command'] ?? null;
        if (! is_string($command) || trim($command) === '') {
            throw DplyManifestException::invalidField(
                fieldPath: "processes.{$name}.command",
                detail: 'must be a non-empty string',
            );
        }

        $scale = 1;
        if (array_key_exists('scale', $entry)) {
            $rawScale = $entry['scale'];
            if (! is_int($rawScale) || $rawScale < 1) {
                throw DplyManifestException::invalidField(
                    fieldPath: "processes.{$name}.scale",
                    detail: 'must be a positive integer',
                );
            }
            $scale = $rawScale;
        }

        return new DplyManifestProcess(
            name: $name,
            command: trim($command),
            scale: $scale,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function collectWarnings(array $data): array
    {
        $warnings = [];
        foreach (array_keys($data) as $key) {
            if (! is_string($key)) {
                continue;
            }
            if (! in_array($key, DplyManifest::KNOWN_TOP_LEVEL_KEYS, true)) {
                $warnings[] = "Unknown top-level key `{$key}` ignored — this version of dply does not recognize it. (Newer manifests may add fields older clients can safely skip.)";
            }
        }

        return $warnings;
    }
}
