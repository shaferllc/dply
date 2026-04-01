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
            ];
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
     * @param  mixed  $value
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
