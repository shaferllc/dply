<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Enumerates the OpenWhisk actions a checked-out repository declares.
 *
 * A serverless Site is an OpenWhisk package that may hold more than one
 * action. Discovery follows the same precedence idea as the runtime
 * detector — the first rule that matches wins:
 *
 *   1. `project.yml` / `project.yaml` — an explicit OpenWhisk manifest;
 *      every action under every package is enumerated from it.
 *   2. A `functions/` directory — each immediate sub-directory is one
 *      action, its runtime auto-detected from the files inside it.
 *   3. Otherwise — the repository is a single action, classified by
 *      {@see ServerlessRuntimeDetector} at the repo root.
 *
 * Each descriptor maps onto one `function_actions` row.
 */
final class ServerlessActionDiscovery
{
    public function __construct(private readonly ServerlessRuntimeDetector $detector) {}

    /**
     * @param  array<string, mixed>  $capabilities
     * @return list<array{
     *     name: string,
     *     package: string,
     *     language: string,
     *     runtime: string,
     *     entrypoint: string,
     *     entry_file: string,
     *     source_subdir: string,
     *     deploy_kind: string,
     *     build_command: string,
     *     confidence: string,
     *     source: string
     * }>
     */
    public function discover(string $workingDirectory, array $capabilities): array
    {
        $fromManifest = $this->fromProjectManifest($workingDirectory);
        if ($fromManifest !== []) {
            return $fromManifest;
        }

        $fromFunctionsDir = $this->fromFunctionsDirectory($workingDirectory, $capabilities);
        if ($fromFunctionsDir !== []) {
            return $fromFunctionsDir;
        }

        return [$this->singleAction($workingDirectory, $capabilities)];
    }

    /**
     * Parse an OpenWhisk `project.yml` into action descriptors. Returns an
     * empty list when there is no manifest, it cannot be parsed, or it
     * declares no actions — so discovery falls through to the next rule.
     *
     * @return list<array<string, mixed>>
     */
    private function fromProjectManifest(string $workingDirectory): array
    {
        $manifestPath = null;
        foreach (['project.yml', 'project.yaml'] as $candidate) {
            if (is_file($workingDirectory.'/'.$candidate)) {
                $manifestPath = $workingDirectory.'/'.$candidate;
                break;
            }
        }

        if ($manifestPath === null) {
            return [];
        }

        try {
            $parsed = Yaml::parse((string) file_get_contents($manifestPath));
        } catch (ParseException) {
            return [];
        }

        $packages = is_array($parsed['packages'] ?? null) ? $parsed['packages'] : [];
        $descriptors = [];

        foreach ($packages as $packageName => $package) {
            $actions = is_array($package['actions'] ?? null) ? $package['actions'] : [];

            foreach ($actions as $actionName => $action) {
                $action = is_array($action) ? $action : [];
                $functionPath = trim((string) ($action['function'] ?? ''));
                $runtime = trim((string) ($action['runtime'] ?? ''));

                $descriptors[] = [
                    'name' => (string) $actionName,
                    'package' => (string) $packageName,
                    'language' => $this->languageForRuntime($runtime),
                    'runtime' => $runtime,
                    'entrypoint' => trim((string) ($action['main'] ?? '')) ?: 'main',
                    'entry_file' => $functionPath !== '' ? basename($functionPath) : '',
                    'source_subdir' => $functionPath !== '' ? trim((string) (pathinfo($functionPath, PATHINFO_DIRNAME)), '.') : '',
                    'deploy_kind' => 'raw',
                    'build_command' => '',
                    'confidence' => 'high',
                    'source' => 'project_yml',
                ];
            }
        }

        return $descriptors;
    }

    /**
     * Treat each immediate sub-directory of `functions/` as one action,
     * detecting its runtime from the files it contains.
     *
     * @param  array<string, mixed>  $capabilities
     * @return list<array<string, mixed>>
     */
    private function fromFunctionsDirectory(string $workingDirectory, array $capabilities): array
    {
        $functionsDir = $workingDirectory.'/functions';
        if (! is_dir($functionsDir)) {
            return [];
        }

        $descriptors = [];
        foreach (scandir($functionsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir($functionsDir.'/'.$entry)) {
                continue;
            }

            $detected = $this->detector->detect($functionsDir.'/'.$entry, $capabilities);
            $descriptors[] = $this->descriptorFromDetection(
                $detected, $entry, 'functions/'.$entry, 'functions_dir',
            );
        }

        return $descriptors;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    private function singleAction(string $workingDirectory, array $capabilities): array
    {
        return $this->descriptorFromDetection(
            $this->detector->detect($workingDirectory, $capabilities),
            '', '', 'single',
        );
    }

    /**
     * @param  array<string, mixed>  $detected
     * @return array<string, mixed>
     */
    private function descriptorFromDetection(array $detected, string $name, string $sourceSubdir, string $source): array
    {
        return [
            'name' => $name,
            'package' => 'default',
            'language' => (string) ($detected['language'] ?? 'unknown'),
            'runtime' => (string) ($detected['runtime'] ?? ''),
            'entrypoint' => (string) ($detected['entrypoint'] ?? 'main'),
            'entry_file' => (string) ($detected['entry_file'] ?? ''),
            'source_subdir' => $sourceSubdir,
            'deploy_kind' => (string) ($detected['deploy_kind'] ?? 'unknown'),
            'build_command' => (string) ($detected['build_command'] ?? ''),
            'confidence' => (string) ($detected['confidence'] ?? 'low'),
            'source' => $source,
        ];
    }

    private function languageForRuntime(string $runtime): string
    {
        return match (true) {
            str_starts_with($runtime, 'nodejs'), str_starts_with($runtime, 'node') => 'node',
            str_starts_with($runtime, 'python') => 'python',
            str_starts_with($runtime, 'php') => 'php',
            str_starts_with($runtime, 'go') => 'go',
            default => 'unknown',
        };
    }
}
