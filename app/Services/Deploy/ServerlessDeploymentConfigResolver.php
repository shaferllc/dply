<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;

final class ServerlessDeploymentConfigResolver
{
    public function __construct(
        private readonly ServerlessTargetCapabilityResolver $capabilityResolver,
    ) {}

    /**
     * @return array{
     *     target: string,
     *     runtime: string,
     *     entrypoint: string,
     *     package: string,
     *     repo_source: string,
     *     source_control_account_id: ?string,
     *     repository_subdirectory: string,
     *     build_command: string,
     *     artifact_output_path: string,
     *     function_name: string,
     *     supports_runtime_detection: bool,
     *     supports_php_runtime: bool,
     *     supports_node_runtime: bool,
     *     host_label: string
     * }
     */
    public function resolve(Site $site): array
    {
        $capabilities = $this->capabilityResolver->forSite($site);
        $config = $site->serverlessConfig();

        return [
            'target' => $capabilities['target'],
            'runtime' => trim((string) ($config['runtime'] ?? $capabilities['default_runtime'])),
            'entrypoint' => trim((string) ($config['entrypoint'] ?? $capabilities['default_entrypoint'])),
            'package' => trim((string) ($config['package'] ?? $capabilities['default_package'])),
            'function_name' => trim((string) ($config['function_name'] ?? $site->id)),
            'repo_source' => trim((string) ($config['repo_source'] ?? 'manual')),
            'source_control_account_id' => $this->nullableString($config['source_control_account_id'] ?? null),
            'repository_subdirectory' => trim((string) ($config['repository_subdirectory'] ?? '')),
            'build_command' => trim((string) ($config['build_command'] ?? '')),
            'artifact_output_path' => trim((string) ($config['artifact_output_path'] ?? '')),
            'supports_runtime_detection' => $capabilities['supports_runtime_detection'],
            'supports_php_runtime' => $capabilities['supports_php_runtime'],
            'supports_node_runtime' => $capabilities['supports_node_runtime'],
            'host_label' => $capabilities['host_label'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function persistResolvedConfig(Site $site, array $overrides = []): array
    {
        $resolved = array_merge($this->resolve($site), $overrides);
        $meta = is_array($site->meta) ? $site->meta : [];
        $functionsConfig = $site->serverlessConfig();

        $meta['serverless'] = array_merge($functionsConfig, [
            'runtime' => trim((string) ($resolved['runtime'] ?? '')),
            'entrypoint' => trim((string) ($resolved['entrypoint'] ?? '')),
            'package' => trim((string) ($resolved['package'] ?? '')),
            'function_name' => trim((string) ($resolved['function_name'] ?? $site->id)),
            'repo_source' => trim((string) ($resolved['repo_source'] ?? 'manual')),
            'source_control_account_id' => $this->nullableString($resolved['source_control_account_id'] ?? null),
            'repository_subdirectory' => trim((string) ($resolved['repository_subdirectory'] ?? '')),
            'build_command' => trim((string) ($resolved['build_command'] ?? '')),
            'artifact_output_path' => trim((string) ($resolved['artifact_output_path'] ?? '')),
        ]);

        if (isset($meta['digitalocean_functions']) && ! isset($functionsConfig['target'])) {
            unset($meta['digitalocean_functions']);
        }

        $site->forceFill(['meta' => $meta]);

        if ($site->exists) {
            $site->save();

            return $this->resolve($site->fresh());
        }

        return $this->resolve($site);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
