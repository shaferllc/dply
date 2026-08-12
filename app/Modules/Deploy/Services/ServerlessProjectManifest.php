<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Modules\Serverless\Support\FunctionConfiguration;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A repository's `project.yml`, parsed into the settings a deploy applies.
 *
 * The manifest is the repo's own statement of how its functions should be
 * deployed — runtimes, entrypoints, resource limits, bound parameters, web
 * exposure, and which files to package. dply previously read it only to
 * *enumerate* actions, and ignored everything else on them; this reads the
 * whole document.
 *
 * Three inheritance levels apply, each overriding the one above:
 * project → package → function. That is the manifest's own rule, and it is
 * why `environment`, `parameters`, and `annotations` are merged rather than
 * replaced as the tree is walked.
 *
 * `${VAR}` references interpolate from the repository's `.env` and the
 * process environment, so a manifest can name a secret without containing it.
 *
 * @see https://docs.digitalocean.com/products/functions/reference/project-configuration/
 */
final class ServerlessProjectManifest
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, array<string, mixed>>  $actions  Keyed "package/action".
     */
    private function __construct(
        public readonly string $path,
        public readonly array $raw,
        private readonly array $actions,
    ) {}

    /**
     * Read the manifest from a checked-out repository, or null when there is
     * none — no manifest is the common case, not an error.
     *
     * @param  array<string, string>  $environment  Extra interpolation values, highest priority.
     */
    public static function fromDirectory(string $workingDirectory, array $environment = []): ?self
    {
        $path = null;
        foreach (['project.yml', 'project.yaml'] as $candidate) {
            if (is_file($workingDirectory.'/'.$candidate)) {
                $path = $workingDirectory.'/'.$candidate;
                break;
            }
        }

        if ($path === null) {
            return null;
        }

        try {
            $parsed = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException) {
            return null;
        }

        if (! is_array($parsed)) {
            return null;
        }

        $values = array_merge(self::dotEnv($workingDirectory), $environment);
        $parsed = self::interpolate($parsed, $values);

        return new self($path, $parsed, self::walk($parsed));
    }

    /**
     * Every action the manifest declares, keyed "package/action".
     *
     * @return array<string, array<string, mixed>>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * One action's resolved settings, or null when the manifest does not
     * declare it. `$package` defaults to the implicit default package.
     *
     * @return array<string, mixed>|null
     */
    public function action(string $name, string $package = 'default'): ?array
    {
        return $this->actions[$package.'/'.$name]
            ?? $this->actions['default/'.$name]
            ?? null;
    }

    public function declares(string $name, string $package = 'default'): bool
    {
        return $this->action($name, $package) !== null;
    }

    /**
     * Flatten the project → package → function tree into resolved actions.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, array<string, mixed>>
     */
    private static function walk(array $parsed): array
    {
        $projectEnvironment = self::map($parsed['environment'] ?? []);
        $projectParameters = self::map($parsed['parameters'] ?? []);
        $projectAnnotations = self::map($parsed['annotations'] ?? []);

        $resolved = [];
        $packages = is_array($parsed['packages'] ?? null) ? $parsed['packages'] : [];

        foreach ($packages as $packageKey => $package) {
            $package = is_array($package) ? $package : [];

            // A package may be a list entry carrying its own `name`, or a map
            // keyed by name — the manifest permits both.
            $packageName = trim((string) ($package['name'] ?? (is_string($packageKey) ? $packageKey : '')));
            if ($packageName === '') {
                $packageName = 'default';
            }

            $packageEnvironment = array_merge($projectEnvironment, self::map($package['environment'] ?? []));
            $packageParameters = array_merge($projectParameters, self::map($package['parameters'] ?? []));
            $packageAnnotations = array_merge($projectAnnotations, self::map($package['annotations'] ?? []));

            $functions = is_array($package['functions'] ?? null)
                ? $package['functions']
                : (is_array($package['actions'] ?? null) ? $package['actions'] : []);

            foreach ($functions as $functionKey => $function) {
                $function = is_array($function) ? $function : [];

                $name = trim((string) ($function['name'] ?? (is_string($functionKey) ? $functionKey : '')));
                if ($name === '') {
                    continue;
                }

                $functionPath = trim((string) ($function['function'] ?? ''));
                $limits = is_array($function['limits'] ?? null) ? $function['limits'] : [];

                $resolved[$packageName.'/'.$name] = [
                    'name' => $name,
                    'package' => $packageName,
                    'runtime' => trim((string) ($function['runtime'] ?? '')),
                    'entrypoint' => trim((string) ($function['main'] ?? '')) ?: 'main',
                    'function_path' => $functionPath,
                    'source_subdir' => self::sourceSubdir($functionPath),
                    'entry_file' => $functionPath !== '' ? basename($functionPath) : '',
                    'environment' => array_merge($packageEnvironment, self::map($function['environment'] ?? [])),
                    'parameters' => array_merge($packageParameters, self::map($function['parameters'] ?? [])),
                    'annotations' => array_merge($packageAnnotations, self::map($function['annotations'] ?? [])),
                    'web_mode' => self::webMode($function),
                    'limits' => self::limits($limits),
                    'include' => self::stringList($function['include'] ?? []),
                    'exclude' => self::stringList($function['exclude'] ?? []),
                    'build' => trim((string) ($function['build'] ?? '')),
                    'docker' => trim((string) ($function['docker'] ?? '')),
                ];
            }
        }

        return $resolved;
    }

    /**
     * `web:` is tri-state in the manifest — true, false, or "raw" — which maps
     * exactly onto dply's own exposure modes.
     *
     * @param  array<string, mixed>  $function
     */
    private static function webMode(array $function): ?string
    {
        if (! array_key_exists('web', $function)) {
            return null;
        }

        $web = $function['web'];

        if (is_string($web) && strtolower(trim($web)) === 'raw') {
            return FunctionConfiguration::MODE_RAW;
        }

        return filter_var($web, FILTER_VALIDATE_BOOL)
            ? FunctionConfiguration::MODE_WEB
            : FunctionConfiguration::MODE_OFF;
    }

    /**
     * Manifest limits use seconds for `timeout` and MB for `memory`; dply
     * stores the timeout in milliseconds, as OpenWhisk's API expects.
     *
     * @param  array<string, mixed>  $limits
     * @return array<string, int>
     */
    private static function limits(array $limits): array
    {
        $out = [];

        if (isset($limits['timeout']) && is_numeric($limits['timeout'])) {
            $timeout = (int) $limits['timeout'];
            // A bare number is seconds in the manifest, but people write
            // milliseconds too; anything above the 15-minute ceiling in
            // seconds can only have been meant as milliseconds.
            $out['timeout'] = $timeout > 900 ? $timeout : $timeout * 1000;
        }

        foreach (['memory' => 'memory', 'concurrency' => 'concurrency', 'logs' => 'logs'] as $key => $target) {
            if (isset($limits[$key]) && is_numeric($limits[$key])) {
                $out[$target] = (int) $limits[$key];
            }
        }

        return $out;
    }

    /** The directory holding the function, relative to the repo root. */
    private static function sourceSubdir(string $functionPath): string
    {
        if ($functionPath === '') {
            return '';
        }

        // `packages/api/hello` is a directory; `packages/api/hello.js` is a
        // single file whose directory is what gets packaged.
        $isFile = pathinfo($functionPath, PATHINFO_EXTENSION) !== '';
        $dir = $isFile ? (string) pathinfo($functionPath, PATHINFO_DIRNAME) : $functionPath;

        return trim($dir === '.' ? '' : $dir, '/');
    }

    /**
     * Normalise a `{key: value}` block. The manifest also allows a list of
     * `{name, value}` maps, which OpenWhisk's own tooling emits.
     *
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $out[$key] = $entry;

                continue;
            }

            if (is_array($entry) && isset($entry['name'])) {
                $out[(string) $entry['name']] = $entry['value'] ?? null;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? trim($entry) : '', $value),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * Replace `${VAR}` references throughout the document.
     *
     * An unresolved reference is left as written rather than blanked: a
     * literal `${…}` in a deployed value is visible and diagnosable, whereas
     * an empty string silently deploys a function with no credential.
     *
     * @param  array<string, string>  $values
     */
    private static function interpolate(mixed $node, array $values): mixed
    {
        if (is_array($node)) {
            $out = [];
            foreach ($node as $key => $value) {
                $out[$key] = self::interpolate($value, $values);
            }

            return $out;
        }

        if (! is_string($node)) {
            return $node;
        }

        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static fn (array $m): string => $values[$m[1]] ?? getenv($m[1]) ?: $m[0],
            $node,
        );
    }

    /**
     * The repository's own `.env`, used as interpolation values.
     *
     * @return array<string, string>
     */
    private static function dotEnv(string $workingDirectory): array
    {
        $path = $workingDirectory.'/.env';
        if (! is_file($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', (string) $line, $m) === 1) {
                $values[$m[1]] = trim($m[2], "\"' ");
            }
        }

        return $values;
    }
}
