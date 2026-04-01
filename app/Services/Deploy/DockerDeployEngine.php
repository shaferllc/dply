<?php

namespace App\Services\Deploy;

final class DockerDeployEngine implements \App\Contracts\DeployEngine
{
    public function __construct(
        private readonly DockerComposeArtifactBuilder $artifactBuilder,
    ) {}

    public function run(DeployContext $context): array
    {
        $site = $context->site();
        $meta = is_array($site->meta) ? $site->meta : [];
        $dockerRuntime = is_array($meta['docker_runtime'] ?? null) ? $meta['docker_runtime'] : [];
        $compose = $this->artifactBuilder->build($site);

        $meta['docker_runtime'] = array_merge($dockerRuntime, [
            'compose_yaml' => $compose,
            'last_deployed_at' => now()->toIso8601String(),
        ]);

        $site->forceFill(['meta' => $meta])->save();

        return [
            'output' => "Docker deploy prepared.\n\n".$compose,
            'sha' => null,
        ];
    }
}
