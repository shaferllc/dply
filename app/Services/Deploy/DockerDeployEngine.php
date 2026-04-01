<?php

namespace App\Services\Deploy;

final class DockerDeployEngine implements \App\Contracts\DeployEngine
{
    public function __construct(
        private readonly DockerSiteDeployer $dockerSiteDeployer,
    ) {}

    public function run(DeployContext $context): array
    {
        $site = $context->site();
        $result = $this->dockerSiteDeployer->deploy($site);
        $meta = is_array($site->fresh()->meta) ? $site->fresh()->meta : [];
        $dockerRuntime = is_array($meta['docker_runtime'] ?? null) ? $meta['docker_runtime'] : [];

        $meta['docker_runtime'] = array_merge($dockerRuntime, [
            'compose_yaml' => $result['compose_yaml'],
            'dockerfile' => $result['dockerfile'],
            'last_deployed_at' => now()->toIso8601String(),
        ]);

        $site->forceFill(['meta' => $meta])->save();

        return [
            'output' => $result['output'],
            'sha' => $result['sha'],
        ];
    }
}
