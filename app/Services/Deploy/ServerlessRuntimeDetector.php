<?php

declare(strict_types=1);

namespace App\Services\Deploy;

final class ServerlessRuntimeDetector
{
    /**
     * @param  array<string, mixed>  $capabilities
     * @return array{
     *     framework: string,
     *     language: string,
     *     runtime: string,
     *     entrypoint: string,
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

        $reasons = [];
        $warnings = [];
        $unsupportedForTarget = false;

        if ($this->looksLikeLaravel($workingDirectory, $composerJson)) {
            $reasons[] = 'Detected Laravel project files such as artisan, bootstrap/app.php, routes/web.php, or laravel/framework in composer.json.';
            $unsupportedForTarget = ! (bool) ($capabilities['supports_php_runtime'] ?? false);

            if ($unsupportedForTarget) {
                $warnings[] = 'This repository looks like Laravel/PHP, but the selected serverless target does not advertise a PHP runtime.';
            }

            $lp = app(LaravelComposerPackageDetector::class)->flags($composerJson);
            foreach (LaravelComposerPackageDetector::PACKAGE_KEYS as $short => $packageName) {
                if ($lp[$short] ?? false) {
                    $reasons[] = 'Detected '.$packageName.' in composer.json.';
                }
            }

            return [
                'framework' => 'laravel',
                'language' => 'php',
                'runtime' => $unsupportedForTarget ? '' : (string) ($capabilities['default_runtime'] ?? ''),
                'entrypoint' => $unsupportedForTarget ? '' : (string) ($capabilities['default_entrypoint'] ?? 'public/index.php'),
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

        if ($this->looksLikeRails($workingDirectory)) {
            $reasons[] = 'Detected Rails markers such as Gemfile with config/application.rb or bin/rails.';
            $unsupportedForTarget = ! (bool) ($capabilities['supports_php_runtime'] ?? false);

            if ($unsupportedForTarget) {
                $warnings[] = 'This repository looks like Ruby on Rails, but the selected serverless target does not advertise a Ruby runtime.';
            }

            return [
                'framework' => 'rails',
                'language' => 'ruby',
                'runtime' => $unsupportedForTarget ? '' : (string) ($capabilities['default_runtime'] ?? ''),
                'entrypoint' => $unsupportedForTarget ? '' : (string) ($capabilities['default_entrypoint'] ?? 'index'),
                'build_command' => 'bundle install && bundle exec rails assets:precompile',
                'artifact_output_path' => '.',
                'package' => (string) ($capabilities['default_package'] ?? 'default'),
                'confidence' => 'high',
                'reasons' => $reasons,
                'warnings' => $warnings,
                'unsupported_for_target' => $unsupportedForTarget,
            ];
        }

        $pythonStack = $this->detectPythonStack($workingDirectory, $capabilities);
        if ($pythonStack !== null) {
            return $pythonStack;
        }

        if ($packageJson !== null) {
            $dependencies = array_merge(
                $this->stringKeys($packageJson['dependencies'] ?? null),
                $this->stringKeys($packageJson['devDependencies'] ?? null),
            );
            $scripts = is_array($packageJson['scripts'] ?? null) ? $packageJson['scripts'] : [];

            if (in_array('next', $dependencies, true)) {
                $reasons[] = 'Detected next dependency in package.json.';

                return [
                    'framework' => 'nextjs',
                    'language' => 'node',
                    'runtime' => (string) ($capabilities['default_runtime'] ?? 'nodejs:18'),
                    'entrypoint' => (string) ($capabilities['default_entrypoint'] ?? 'index'),
                    'build_command' => isset($scripts['build']) ? 'npm install && npm run build' : 'npm install',
                    'artifact_output_path' => 'out',
                    'package' => (string) ($capabilities['default_package'] ?? 'default'),
                    'confidence' => 'medium',
                    'reasons' => $reasons,
                    'warnings' => ['Next.js repos may need target-specific export or adapter steps before a serverless zip is deployable.'],
                    'unsupported_for_target' => false,
                ];
            }

            if (in_array('nuxt', $dependencies, true)) {
                $reasons[] = 'Detected nuxt dependency in package.json.';

                return [
                    'framework' => 'nuxt',
                    'language' => 'node',
                    'runtime' => (string) ($capabilities['default_runtime'] ?? 'nodejs:18'),
                    'entrypoint' => (string) ($capabilities['default_entrypoint'] ?? 'index'),
                    'build_command' => isset($scripts['build']) ? 'npm install && npm run build' : 'npm install',
                    'artifact_output_path' => '.output',
                    'package' => (string) ($capabilities['default_package'] ?? 'default'),
                    'confidence' => 'medium',
                    'reasons' => $reasons,
                    'warnings' => ['Nuxt repos may need target-specific serverless adapters before deployment.'],
                    'unsupported_for_target' => false,
                ];
            }

            if (in_array('vite', $dependencies, true) || isset($scripts['build'])) {
                $reasons[] = 'Detected package.json build scripts suitable for a Node-based build.';

                return [
                    'framework' => in_array('vite', $dependencies, true) ? 'vite_static' : 'node_generic',
                    'language' => 'node',
                    'runtime' => (string) ($capabilities['default_runtime'] ?? 'nodejs:18'),
                    'entrypoint' => (string) ($capabilities['default_entrypoint'] ?? 'index'),
                    'build_command' => isset($scripts['build']) ? 'npm install && npm run build' : 'npm install',
                    'artifact_output_path' => in_array('vite', $dependencies, true) ? 'dist' : '.',
                    'package' => (string) ($capabilities['default_package'] ?? 'default'),
                    'confidence' => isset($scripts['build']) ? 'high' : 'medium',
                    'reasons' => $reasons,
                    'warnings' => [],
                    'unsupported_for_target' => false,
                ];
            }
        }

        if ($composerJson !== null && $this->looksLikeSymfony($composerJson)) {
            $reasons[] = 'Detected Symfony via symfony/framework-bundle or symfony/symfony in composer.json.';
            $unsupportedForTarget = ! (bool) ($capabilities['supports_php_runtime'] ?? false);

            if ($unsupportedForTarget) {
                $warnings[] = 'This repository looks like Symfony/PHP, but the selected serverless target does not advertise a PHP runtime.';
            }

            return [
                'framework' => 'symfony',
                'language' => 'php',
                'runtime' => $unsupportedForTarget ? '' : (string) ($capabilities['default_runtime'] ?? ''),
                'entrypoint' => $unsupportedForTarget ? '' : (string) ($capabilities['default_entrypoint'] ?? 'public/index.php'),
                'build_command' => 'composer install --no-dev --optimize-autoloader',
                'artifact_output_path' => 'public/build',
                'package' => (string) ($capabilities['default_package'] ?? 'default'),
                'confidence' => 'medium',
                'reasons' => $reasons,
                'warnings' => $warnings,
                'unsupported_for_target' => $unsupportedForTarget,
            ];
        }

        if ($composerJson !== null) {
            $reasons[] = 'Detected composer.json without strong framework matches.';
            $unsupportedForTarget = ! (bool) ($capabilities['supports_php_runtime'] ?? false);

            if ($unsupportedForTarget) {
                $warnings[] = 'This repository looks like a PHP project, but the selected serverless target does not advertise a PHP runtime.';
            }

            return [
                'framework' => 'php_generic',
                'language' => 'php',
                'runtime' => $unsupportedForTarget ? '' : (string) ($capabilities['default_runtime'] ?? ''),
                'entrypoint' => $unsupportedForTarget ? '' : (string) ($capabilities['default_entrypoint'] ?? 'public/index.php'),
                'build_command' => 'composer install --no-dev --optimize-autoloader',
                'artifact_output_path' => '.',
                'package' => (string) ($capabilities['default_package'] ?? 'default'),
                'confidence' => 'medium',
                'reasons' => $reasons,
                'warnings' => $warnings,
                'unsupported_for_target' => $unsupportedForTarget,
            ];
        }

        if (is_file($workingDirectory.'/index.html')) {
            return [
                'framework' => 'static',
                'language' => 'static',
                'runtime' => (string) ($capabilities['default_runtime'] ?? 'nodejs:18'),
                'entrypoint' => (string) ($capabilities['default_entrypoint'] ?? 'index'),
                'build_command' => '',
                'artifact_output_path' => '.',
                'package' => (string) ($capabilities['default_package'] ?? 'default'),
                'confidence' => 'medium',
                'reasons' => ['Detected a static site root with index.html.'],
                'warnings' => [],
                'unsupported_for_target' => false,
            ];
        }

        return [
            'framework' => 'unknown',
            'language' => 'unknown',
            'runtime' => (string) ($capabilities['default_runtime'] ?? ''),
            'entrypoint' => (string) ($capabilities['default_entrypoint'] ?? ''),
            'build_command' => '',
            'artifact_output_path' => '.',
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => 'low',
            'reasons' => ['No clear framework markers were detected in the repository root.'],
            'warnings' => ['Review and override the runtime, build command, and output path before deploying if this repo is not a simple static or Node build.'],
            'unsupported_for_target' => false,
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

    private function looksLikeRails(string $workingDirectory): bool
    {
        if (! is_file($workingDirectory.'/Gemfile')) {
            return false;
        }

        return is_file($workingDirectory.'/config/application.rb')
            || is_file($workingDirectory.'/bin/rails');
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array{
     *     framework: string,
     *     language: string,
     *     runtime: string,
     *     entrypoint: string,
     *     build_command: string,
     *     artifact_output_path: string,
     *     package: string,
     *     confidence: string,
     *     reasons: list<string>,
     *     warnings: list<string>,
     *     unsupported_for_target: bool
     * }|null
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

        $base = fn (string $framework, string $confidence, string $build, array $reasons) => [
            'framework' => $framework,
            'language' => 'python',
            'runtime' => $unsupportedForTarget ? '' : (string) ($capabilities['default_python_runtime'] ?? 'python3.12'),
            'entrypoint' => $unsupportedForTarget ? '' : (string) ($capabilities['default_entrypoint'] ?? 'main:app'),
            'build_command' => $build,
            'artifact_output_path' => '.',
            'package' => (string) ($capabilities['default_package'] ?? 'default'),
            'confidence' => $confidence,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'unsupported_for_target' => $unsupportedForTarget,
        ];

        if ($hasManagePy || str_contains($combined, 'django')) {
            return $base('django', 'high', 'pip install -r requirements.txt 2>/dev/null || pip install .', [
                'Detected Django (manage.py and/or django in Python dependencies).',
            ]);
        }

        if (str_contains($combined, 'fastapi') || str_contains($combined, 'starlette')) {
            return $base('fastapi', 'high', 'pip install -r requirements.txt 2>/dev/null || pip install .', [
                'Detected FastAPI / Starlette in Python dependencies.',
            ]);
        }

        if (str_contains($combined, 'flask')) {
            return $base('flask', 'high', 'pip install -r requirements.txt 2>/dev/null || pip install .', [
                'Detected Flask in Python dependencies.',
            ]);
        }

        if ($hasPyProject || $hasRequirements || $hasPipfile || $hasPyLock) {
            return $base('python_generic', 'medium', 'pip install -r requirements.txt 2>/dev/null || pip install .', [
                'Detected Python packaging (pyproject.toml, requirements.txt, Pipfile, or lockfile).',
            ]);
        }

        return null;
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
