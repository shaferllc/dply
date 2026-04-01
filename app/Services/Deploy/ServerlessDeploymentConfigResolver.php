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
     *     supports_runtime_detection: bool,
     *     supports_php_runtime: bool,
     *     supports_node_runtime: bool
     * }
     */
    public function resolve(Site $site): array
    {
        $capabilities = $this->capabilityResolver->forSite($site);
        $config = $site->functionsConfig();

        return [
            'target' => $capabilities['target'],
            'runtime' => trim((string) ($config['runtime'] ?? $capabilities['default_runtime'])),
            'entrypoint' => trim((string) ($config['entrypoint'] ?? $capabilities['default_entrypoint'])),
            'package' => trim((string) ($config['package'] ?? $capabilities['default_package'])),
            'repo_source' => trim((string) ($config['repo_source'] ?? 'manual')),
            'source_control_account_id' => $this->nullableString($config['source_control_account_id'] ?? null),
            'repository_subdirectory' => trim((string) ($config['repository_subdirectory'] ?? '')),
            'build_command' => trim((string) ($config['build_command'] ?? '')),
            'artifact_output_path' => trim((string) ($config['artifact_output_path'] ?? '')),
            'supports_runtime_detection' => $capabilities['supports_runtime_detection'],
            'supports_php_runtime' => $capabilities['supports_php_runtime'],
            'supports_node_runtime' => $capabilities['supports_node_runtime'],
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
        $functionsConfig = $site->functionsConfig();

        $meta['digitalocean_functions'] = array_merge($functionsConfig, [
            'runtime' => trim((string) ($resolved['runtime'] ?? '')),
            'entrypoint' => trim((string) ($resolved['entrypoint'] ?? '')),
            'package' => trim((string) ($resolved['package'] ?? '')),
            'repo_source' => trim((string) ($resolved['repo_source'] ?? 'manual')),
            'source_control_account_id' => $this->nullableString($resolved['source_control_account_id'] ?? null),
            'repository_subdirectory' => trim((string) ($resolved['repository_subdirectory'] ?? '')),
            'build_command' => trim((string) ($resolved['build_command'] ?? '')),
            'artifact_output_path' => trim((string) ($resolved['artifact_output_path'] ?? '')),
        ]);

        $site->forceFill(['meta' => $meta])->save();

        return $this->resolve($site->fresh());
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
