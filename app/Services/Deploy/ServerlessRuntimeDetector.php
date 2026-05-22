<?php

declare(strict_types=1);

namespace App\Services\Deploy;

/**
 * Classifies a checked-out repository for serverless deployment.
 *
 * Detection follows a strict precedence ladder — the first rule that matches
 * wins, so the result is deterministic and debuggable:
 *
 *   1. Framework markers   → a framework app (gets a dply __ow_* adapter):
 *                            Laravel, Symfony, Django, FastAPI, Flask,
 *                            Next.js, Nuxt, Vite.
 *   2. Generic project     → a language project with dependency manifests
 *                            but no recognised framework (php/node/python).
 *   3. OpenWhisk project   → a `project.yml` manifest → a raw multi-action
 *                            package.
 *   4. Single entry point  → a root `main.*` file exporting a `main` handler
 *                            → a raw single OpenWhisk action.
 *   5. Static site         → an `index.html` root.
 *   6. Unknown             → nothing recognised; the caller must supply a
 *                            runtime + entrypoint before deploying.
 *
 * Every result carries `deploy_kind`: `framework` (needs an adapter), `raw`
 * (a bare OpenWhisk action — gets a logging shim instead), `static`, or
 * `unknown`. `entry_file` is the resolved root entry file for raw actions.
 *
 * Note: this detector is shared with LocalRuntimeDetector (container/VM
 * runtime detection), so existing `framework` values are preserved.
 */
final class ServerlessRuntimeDetector
{
    /** Default OpenWhisk runtime kind per language, used for raw actions. */
    private const RAW_RUNTIME_DEFAULTS = [
        'php' => 'php:8.3',
        'node' => 'nodejs:18',
        'python' => 'python:3.11',
        'go' => 'go:1.22',
    ];

    /**
     * Root entry-file candidates per language, in the order they are tried.
     * The file must also contain the language's `main` handler symbol.
     *
     * @var array<string, list<string>>
     */
    private const RAW_ENTRY_CANDIDATES = [
        'php' => ['main.php'],
        'node' => ['main.js', 'main.mjs', 'index.js', 'index.mjs'],
        'python' => ['main.py', '__main__.py'],
        'go' => ['main.go'],
    ];

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array{
     *     framework: string,
     *     deploy_kind: string,
     *     language: string,
     *     runtime: string,
     *     entrypoint: string,
     *     entry_file: string,
     *     build_command: string,
     *     artifact_output_path: string,
     *     package: string,
     *     confidence: string,
     *     reasons: list<string>,
     *     warnings: list<string>,
     *     unsupported_for_target: bool
     * }
     */
    public function detect(string $workingDirectory, array $capabilities): array
    {
        $packageJson = $this->readJson($workingDirectory.'/package.json');
        $composerJson = $this->readJson($workingDirectory.'/composer.json');

        // ---- 1. Framework markers ------------------------------------------
        $laravel = $this->detectLaravel($workingDirectory, $composerJson, $capabilities);
        if ($laravel !== null) {
            return $laravel;
        }

        $pythonStack = $this->detectPythonStack($workingDirectory, $capabilities);
        if ($pythonStack !== null && $this->isFrameworkPython($pythonStack['framework'])) {
            return $pythonStack;
        }

        $nodeFramework = $this->detectNodeStack($packageJson, $capabilities);
        if ($nodeFramework !== null) {
            return $nodeFramework;
        }

        $goFramework = $this->detectGoStack($workingDirectory, $capabilities);
        if ($goFramework !== null) {
            return $goFramework;
        }

        if ($composerJson !== null && $this->looksLikeSymfony($composerJson)) {
            return $this->phpResult('symfony', 'medium', 'public/build', [
                'Detected Symfony via symfony/framework-bundle or symfony/symfony in composer.json.',
            ], $capabilities, 'framework');
        }

        // ---- 2. Generic language projects (no recognised framework) --------
        if ($pythonStack !== null) {
            // python_generic — Python packaging but no web framework.
            return $pythonStack;
        }

        if ($composerJson !== null) {
            return $this->phpResult('php_generic', 'medium', '.', [
                'Detected composer.json without strong framework matches.',
            ], $capabilities, 'raw');
        }

        // ---- 3. OpenWhisk project.yml → raw multi-action package -----------
        if (is_file($workingDirectory.'/project.yml') || is_file($workingDirectory.'/project.yaml')) {
            return [
                'framework' => 'raw',
                'deploy_kind' => 'raw',
                'language' => 'mixed',
                'runtime' => '',
                'entrypoint' => 'main',
                'entry_file' => '',
                'build_command' => '',
                'artifact_output_path' => '.',
                'package' => (string) ($capabilities['default_package'] ?? 'default'),
                'confidence' => 'high',
                'reasons' => ['Detected an OpenWhisk project.yml manifest — a raw multi-action package.'],
                'warnings' => ['Individual actions are enumerated from the manifest at deploy time.'],
                'unsupported_for_target' => false,
            ];
        }

        // ---- 4. Single root entry point → raw single OpenWhisk action ------
        $rawAction = $this->detectRawAction($workingDirectory, $capabilities);
        if ($rawAction !== null) {
            return $rawAction;
        }

        // ---- 5. Static site ------------------------------------------------
        if (is_file($workingDirectory.'/index.html')) {
            return [
                'framework' => 'static',
                'deploy_kind' => 'static',
                'language' => 'static',
                'runtime' => (string) ($capabilities['default_runtime'] ?? 'nodejs:18'),
                'entrypoint' => (string) ($capabilities['default_entrypoint'] ?? 'index'),
                'entry_file' => 'index.html',
                'build_command' => '',
                'artifact_output_path' => '.',
                'package' => (string) ($capabilities['default_package'] ?? 'default'),
                'confidence' => 'medium',
                'reasons' => ['Detected a static site root with index.html.'],
                'warnings' => [],
                'unsupported_for_target' => false,
            ];
        }

        // ---- 6. Unknown ----------------------------------------------------
        return [
            'framework' => 'unknown',
            'deploy_kind' => 'unknown',
            'language' => 'unknown',
            'runtime' => (string) ($capabilities['default_runtime'] ?? ''),
            'entrypoint' => (string) ($capabilities['default_entrypoint'] ?? ''),
            'entry_file' => '',
            'build_command' => '',
            'artifact_output_path' => '.',
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => 'low',
            'reasons' => ['No clear framework markers were detected in the repository root.'],
            'warnings' => ['Set the runtime and entrypoint manually before deploying — this repo has no framework, no OpenWhisk project.yml, and no recognised main() entry file.'],
            'unsupported_for_target' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $composerJson
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>|null
     */
    private function detectLaravel(string $workingDirectory, ?array $composerJson, array $capabilities): ?array
    {
        if (! $this->looksLikeLaravel($workingDirectory, $composerJson)) {
            return null;
        }

        $reasons = ['Detected Laravel project files such as artisan, bootstrap/app.php, routes/web.php, or laravel/framework in composer.json.'];
        $unsupportedForTarget = ! (bool) ($capabilities['supports_php_runtime'] ?? false);
        $warnings = [];
        if ($unsupportedForTarget) {
            $warnings[] = 'This repository looks like Laravel/PHP, but the selected serverless target does not advertise a PHP runtime.';
        }

        $lp = app(LaravelComposerPackageDetector::class)->flags(is_array($composerJson) ? $composerJson : []);
        foreach (LaravelComposerPackageDetector::PACKAGE_KEYS as $short => $packageName) {
            if ($lp[$short] ?? false) {
                $reasons[] = 'Detected '.$packageName.' in composer.json.';
            }
        }

        return [
            'framework' => 'laravel',
            'deploy_kind' => 'framework',
            'language' => 'php',
            'runtime' => $unsupportedForTarget ? '' : (string) ($capabilities['default_runtime'] ?? ''),
            'entrypoint' => $unsupportedForTarget ? '' : (string) ($capabilities['default_entrypoint'] ?? 'public/index.php'),
            'entry_file' => '',
            'build_command' => 'composer install --no-dev --optimize-autoloader',
            'artifact_output_path' => '.',
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => 'high',
            'reasons' => $reasons,
            'warnings' => $warnings,
            'unsupported_for_target' => $unsupportedForTarget,
            'laravel_octane' => $lp['octane'],
            'laravel_horizon' => $lp['horizon'],
            'laravel_pulse' => $lp['pulse'],
            'laravel_reverb' => $lp['reverb'],
        ];
    }

    /**
     * Detect a raw OpenWhisk single action — a root entry file that defines
     * the language's `main` handler symbol.
     *
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>|null
     */
    private function detectRawAction(string $workingDirectory, array $capabilities): ?array
    {
        $matched = [];
        foreach (self::RAW_ENTRY_CANDIDATES as $language => $candidates) {
            foreach ($candidates as $candidate) {
                $path = $workingDirectory.'/'.$candidate;
                if (is_file($path) && $this->hasMainSymbol($language, (string) file_get_contents($path))) {
                    $matched[$language] = $candidate;
                    break;
                }
            }
        }

        if ($matched === []) {
            return null;
        }

        // Strict precedence when more than one language has an entry file.
        $language = array_key_first($matched);
        $entryFile = $matched[$language];

        $warnings = [];
        if (count($matched) > 1) {
            $warnings[] = 'Multiple raw entry files found ('.implode(', ', $matched).'); using '.$entryFile.'. Set the runtime explicitly to pick another.';
        }

        $supported = $this->languageSupported($language, $capabilities);
        if (! $supported) {
            $warnings[] = 'This looks like a raw '.$language.' action, but the selected serverless target does not advertise a '.$language.' runtime.';
        }

        return [
            'framework' => 'raw',
            'deploy_kind' => 'raw',
            'language' => $language,
            'runtime' => $supported ? $this->rawRuntime($language, $capabilities) : '',
            // OpenWhisk exec.main — the handler symbol the runtime calls.
            'entrypoint' => 'main',
            'entry_file' => $entryFile,
            'build_command' => $this->rawBuildCommand($language, $workingDirectory),
            'artifact_output_path' => '.',
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => 'high',
            'reasons' => ['Detected a raw OpenWhisk '.$language.' action — '.$entryFile.' defines a main() handler.'],
            'warnings' => $warnings,
            'unsupported_for_target' => ! $supported,
        ];
    }

    private function hasMainSymbol(string $language, string $source): bool
    {
        return match ($language) {
            'php' => (bool) preg_match('/function\s+main\s*\(/i', $source),
            'node' => (bool) preg_match('/(?:exports\.main|module\.exports\s*=|export\s+(?:async\s+)?function\s+main|export\s+const\s+main|function\s+main|const\s+main\s*=)/', $source),
            'python' => (bool) preg_match('/def\s+main\s*\(/', $source),
            'go' => (bool) preg_match('/func\s+Main\s*\(/', $source),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function languageSupported(string $language, array $capabilities): bool
    {
        return match ($language) {
            'php' => (bool) ($capabilities['supports_php_runtime'] ?? false),
            'node' => (bool) ($capabilities['supports_node_runtime'] ?? false),
            'python' => (bool) ($capabilities['supports_python_runtime'] ?? false),
            'go' => (bool) ($capabilities['supports_go_runtime'] ?? false),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function rawRuntime(string $language, array $capabilities): string
    {
        // Honour the target's advertised default where it has one; otherwise
        // fall back to dply's per-language default.
        $fromCapabilities = match ($language) {
            'node' => (string) ($capabilities['default_runtime'] ?? ''),
            'python' => (string) ($capabilities['default_python_runtime'] ?? ''),
            default => '',
        };

        if ($fromCapabilities !== '' && str_starts_with($fromCapabilities, $this->runtimePrefix($language))) {
            return $fromCapabilities;
        }

        return self::RAW_RUNTIME_DEFAULTS[$language] ?? '';
    }

    private function runtimePrefix(string $language): string
    {
        return match ($language) {
            'node' => 'node',
            'python' => 'python',
            'php' => 'php',
            'go' => 'go',
            default => $language,
        };
    }

    private function rawBuildCommand(string $language, string $workingDirectory): string
    {
        return match ($language) {
            'node' => is_file($workingDirectory.'/package.json') ? 'npm install' : '',
            'python' => is_file($workingDirectory.'/requirements.txt') ? 'pip install -r requirements.txt' : '',
            'php' => is_file($workingDirectory.'/composer.json') ? 'composer install --no-dev --optimize-autoloader' : '',
            // Go is compiled by the DigitalOcean Functions remote builder.
            default => '',
        };
    }

    private function isFrameworkPython(string $framework): bool
    {
        return in_array($framework, ['django', 'fastapi', 'flask'], true);
    }

    /**
     * Build a PHP framework / generic result.
     *
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    private function phpResult(string $framework, string $confidence, string $artifactOutputPath, array $reasons, array $capabilities, string $deployKind): array
    {
        $unsupportedForTarget = ! (bool) ($capabilities['supports_php_runtime'] ?? false);
        $warnings = [];
        if ($unsupportedForTarget) {
            $warnings[] = 'This repository looks like a PHP project, but the selected serverless target does not advertise a PHP runtime.';
        }

        return [
            'framework' => $framework,
            'deploy_kind' => $deployKind,
            'language' => 'php',
            'runtime' => $unsupportedForTarget ? '' : (string) ($capabilities['default_runtime'] ?? ''),
            'entrypoint' => $unsupportedForTarget ? '' : (string) ($capabilities['default_entrypoint'] ?? 'public/index.php'),
            'entry_file' => '',
            'build_command' => 'composer install --no-dev --optimize-autoloader',
            'artifact_output_path' => $artifactOutputPath,
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => $confidence,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'unsupported_for_target' => $unsupportedForTarget,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $packageJson
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>|null
     */
    private function detectNodeStack(?array $packageJson, array $capabilities): ?array
    {
        if ($packageJson === null) {
            return null;
        }

        $dependencies = array_merge(
            $this->stringKeys($packageJson['dependencies'] ?? null),
            $this->stringKeys($packageJson['devDependencies'] ?? null),
        );
        $scripts = is_array($packageJson['scripts'] ?? null) ? $packageJson['scripts'] : [];

        $node = fn (string $framework, string $deployKind, string $artifactOutputPath, string $confidence, array $reasons, array $warnings): array => [
            'framework' => $framework,
            'deploy_kind' => $deployKind,
            'language' => 'node',
            'runtime' => (string) ($capabilities['default_runtime'] ?? 'nodejs:18'),
            'entrypoint' => (string) ($capabilities['default_entrypoint'] ?? 'index'),
            'entry_file' => '',
            'build_command' => isset($scripts['build']) ? 'npm install && npm run build' : 'npm install',
            'artifact_output_path' => $artifactOutputPath,
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => $confidence,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'unsupported_for_target' => false,
        ];

        if (in_array('express', $dependencies, true)) {
            return $node('express', 'framework', '.', 'high',
                ['Detected the express dependency in package.json.'],
                [],
            );
        }

        if (in_array('next', $dependencies, true)) {
            return $node('nextjs', 'framework', 'out', 'medium',
                ['Detected next dependency in package.json.'],
                ['Next.js repos may need target-specific export or adapter steps before a serverless zip is deployable.'],
            );
        }

        if (in_array('nuxt', $dependencies, true)) {
            return $node('nuxt', 'framework', '.output', 'medium',
                ['Detected nuxt dependency in package.json.'],
                ['Nuxt repos may need target-specific serverless adapters before deployment.'],
            );
        }

        if (in_array('vite', $dependencies, true) || isset($scripts['build'])) {
            $isVite = in_array('vite', $dependencies, true);

            return $node(
                $isVite ? 'vite_static' : 'node_generic',
                $isVite ? 'static' : 'raw',
                $isVite ? 'dist' : '.',
                isset($scripts['build']) ? 'high' : 'medium',
                ['Detected package.json build scripts suitable for a Node-based build.'],
                [],
            );
        }

        return null;
    }

    /**
     * Detect a Go web framework from go.mod. Currently recognises Gin; a
     * generic Go module falls through to raw-action detection.
     *
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>|null
     */
    private function detectGoStack(string $workingDirectory, array $capabilities): ?array
    {
        if (! is_file($workingDirectory.'/go.mod')) {
            return null;
        }

        $goMod = (string) file_get_contents($workingDirectory.'/go.mod');
        if (! str_contains($goMod, 'github.com/gin-gonic/gin')) {
            return null;
        }

        $supported = (bool) ($capabilities['supports_go_runtime'] ?? false);

        return [
            'framework' => 'gin',
            'deploy_kind' => 'framework',
            'language' => 'go',
            'runtime' => $supported ? (self::RAW_RUNTIME_DEFAULTS['go'] ?? 'go:1.22') : '',
            'entrypoint' => 'main',
            'entry_file' => '',
            'build_command' => '',
            'artifact_output_path' => '.',
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => 'high',
            'reasons' => ['Detected the gin-gonic/gin dependency in go.mod.'],
            'warnings' => $supported ? [] : ['This repository looks like Gin/Go, but the selected serverless target does not advertise a Go runtime.'],
            'unsupported_for_target' => ! $supported,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $composerJson
     */
    private function looksLikeLaravel(string $workingDirectory, ?array $composerJson): bool
    {
        if (is_file($workingDirectory.'/artisan') && is_file($workingDirectory.'/bootstrap/app.php')) {
            return true;
        }

        if (is_file($workingDirectory.'/routes/web.php') && is_file($workingDirectory.'/public/index.php')) {
            return true;
        }

        $require = is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [];

        return array_key_exists('laravel/framework', $require);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>|null
     */
    private function detectPythonStack(string $workingDirectory, array $capabilities): ?array
    {
        $hasManagePy = is_file($workingDirectory.'/manage.py');
        $hasPyProject = is_file($workingDirectory.'/pyproject.toml');
        $hasRequirements = is_file($workingDirectory.'/requirements.txt');
        $hasPipfile = is_file($workingDirectory.'/Pipfile');
        $hasPyLock = is_file($workingDirectory.'/uv.lock') || is_file($workingDirectory.'/poetry.lock');

        if (! $hasManagePy && ! $hasPyProject && ! $hasRequirements && ! $hasPipfile && ! $hasPyLock) {
            return null;
        }

        $reqBlob = strtolower($hasRequirements ? (string) file_get_contents($workingDirectory.'/requirements.txt') : '');
        $pipBlob = $hasPipfile ? strtolower((string) file_get_contents($workingDirectory.'/Pipfile')) : '';
        $pyProjectBlob = $hasPyProject ? strtolower((string) file_get_contents($workingDirectory.'/pyproject.toml')) : '';
        $combined = $reqBlob.' '.$pipBlob.' '.$pyProjectBlob;

        $supportsPython = (bool) ($capabilities['supports_python_runtime'] ?? true);
        $unsupportedForTarget = ! $supportsPython;
        $warnings = [];
        if ($unsupportedForTarget) {
            $warnings[] = 'This repository looks like Python, but the selected target may not advertise a Python runtime.';
        }

        $base = fn (string $framework, string $deployKind, string $confidence, string $build, array $reasons): array => [
            'framework' => $framework,
            'deploy_kind' => $deployKind,
            'language' => 'python',
            'runtime' => $unsupportedForTarget ? '' : (string) ($capabilities['default_python_runtime'] ?? 'python3.12'),
            'entrypoint' => $unsupportedForTarget ? '' : (string) ($capabilities['default_entrypoint'] ?? 'main:app'),
            'entry_file' => '',
            'build_command' => $build,
            'artifact_output_path' => '.',
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => $confidence,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'unsupported_for_target' => $unsupportedForTarget,
        ];

        if ($hasManagePy || str_contains($combined, 'django')) {
            return $base('django', 'framework', 'high', 'pip install -r requirements.txt 2>/dev/null || pip install .', [
                'Detected Django (manage.py and/or django in Python dependencies).',
            ]);
        }

        if (str_contains($combined, 'fastapi') || str_contains($combined, 'starlette')) {
            return $base('fastapi', 'framework', 'high', 'pip install -r requirements.txt 2>/dev/null || pip install .', [
                'Detected FastAPI / Starlette in Python dependencies.',
            ]);
        }

        if (str_contains($combined, 'flask')) {
            return $base('flask', 'framework', 'high', 'pip install -r requirements.txt 2>/dev/null || pip install .', [
                'Detected Flask in Python dependencies.',
            ]);
        }

        return $base('python_generic', 'raw', 'medium', 'pip install -r requirements.txt 2>/dev/null || pip install .', [
            'Detected Python packaging (pyproject.toml, requirements.txt, Pipfile, or lockfile).',
        ]);
    }

    /**
     * @param  array<string, mixed>  $composerJson
     */
    private function looksLikeSymfony(array $composerJson): bool
    {
        $require = is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [];

        return array_key_exists('symfony/framework-bundle', $require)
            || array_key_exists('symfony/symfony', $require);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    private function stringKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): ?string => is_string($key) ? $key : null, array_keys($value))
        ));
    }
}
