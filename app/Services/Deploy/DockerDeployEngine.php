<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

final class DockerDeployEngine implements DeployEngine
{
    public function __construct(
        private readonly DockerSiteDeployer $dockerSiteDeployer,
        private readonly LocalDockerRuntimeManager $localRuntimeManager,
        private readonly DeploymentContractBuilder $contractBuilder,
        private readonly DeploymentRevisionTracker $revisionTracker,
    ) {}

    public function run(DeployContext $context): array
    {
        $site = $context->site();
        $result = $site->runtimeTargetFamily() === 'local_orbstack_docker'
            ? $this->localRuntimeManager->deploy($site)
            : $this->dockerSiteDeployer->deploy($site);
        $meta = is_array($site->fresh()->meta) ? $site->fresh()->meta : [];
        $dockerRuntime = is_array($meta['docker_runtime'] ?? null) ? $meta['docker_runtime'] : [];

        $meta['docker_runtime'] = array_merge($dockerRuntime, [
            'compose_yaml' => $result['compose_yaml'] ?? ($dockerRuntime['compose_yaml'] ?? null),
            'dockerfile' => $result['dockerfile'] ?? ($dockerRuntime['dockerfile'] ?? null),
            'workspace_path' => $result['workspace_path'] ?? ($dockerRuntime['workspace_path'] ?? null),
            'repository_checkout_path' => $result['repository_checkout_path'] ?? ($dockerRuntime['repository_checkout_path'] ?? null),
            'working_directory' => $result['working_directory'] ?? ($dockerRuntime['working_directory'] ?? null),
            'generated_compose_path' => $result['generated_compose_path'] ?? ($dockerRuntime['generated_compose_path'] ?? null),
            'generated_dockerfile_path' => $result['generated_dockerfile_path'] ?? ($dockerRuntime['generated_dockerfile_path'] ?? null),
            'runtime_details' => $result['runtime_details'] ?? ($dockerRuntime['runtime_details'] ?? null),
            'last_status' => $result['status'] ?? ($dockerRuntime['last_status'] ?? null),
            'last_output' => $result['output'] ?? null,
            'last_deployed_at' => now()->toIso8601String(),
        ]);
        $meta['runtime_target'] = array_merge($site->runtimeTarget(), [
            'status' => $result['status'] ?? 'running',
            'publication' => array_merge(
                is_array(data_get($site->runtimeTarget(), 'publication')) ? data_get($site->runtimeTarget(), 'publication') : [],
                is_array($result['publication'] ?? null) ? $result['publication'] : [],
            ),
            'last_deployed_at' => now()->toIso8601String(),
            'last_operation' => 'deploy',
            'last_operation_output' => $result['output'] ?? null,
            'last_revision_id' => $result['sha'] ?? null,
        ]);

        $site->forceFill(['meta' => $meta])->save();
        $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'runtime');

        return [
            'output' => $result['output'],
            'sha' => $result['sha'],
        ];
    }
}
