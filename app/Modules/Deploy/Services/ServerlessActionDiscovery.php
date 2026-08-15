<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

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
     * @param  array<string, mixed> $capabilities
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
    /**
     * @return array<int, array<string, mixed>>
     * @param  array<string, mixed> $capabilities
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
     * Turn the repository's `project.yml` into action descriptors. Returns an
     * empty list when there is no manifest, it cannot be parsed, or it
     * declares no actions — so discovery falls through to the next rule.
     *
     * The manifest carries far more than names: limits, bound parameters,
     * annotations, web exposure, and packaging filters all come through on
     * the descriptor so the deployer can honour what the repo asked for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromProjectManifest(string $workingDirectory): array
    {
        $manifest = ServerlessProjectManifest::fromDirectory($workingDirectory);
        if ($manifest === null) {
            return [];
        }

        $descriptors = [];

        foreach ($manifest->actions() as $action) {
            $runtime = (string) $action['runtime'];

            $descriptors[] = [
                'name' => (string) $action['name'],
                'package' => (string) $action['package'],
                'language' => $this->languageForRuntime($runtime),
                'runtime' => $runtime,
                'entrypoint' => (string) $action['entrypoint'],
                'entry_file' => (string) $action['entry_file'],
                'source_subdir' => (string) $action['source_subdir'],
                'deploy_kind' => 'raw',
                'build_command' => (string) $action['build'],
                'confidence' => 'high',
                'source' => 'project_yml',
                'environment' => $action['environment'],
                'parameters' => $action['parameters'],
                'annotations' => $action['annotations'],
                'web_mode' => $action['web_mode'],
                'limits' => $action['limits'],
                'include' => $action['include'],
                'exclude' => $action['exclude'],
            ];
        }

        return $descriptors;
    }

    /**
     * Treat each immediate sub-directory of `functions/` as one action,
     * detecting its runtime from the files it contains.
     *
     * @param  array<string, mixed> $capabilities
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
     * @param  array<string, mixed> $capabilities
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
     * @param  array<string, mixed> $detected
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
