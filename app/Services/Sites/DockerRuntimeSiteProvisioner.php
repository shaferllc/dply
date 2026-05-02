<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Deploy\DockerComposeArtifactBuilder;
use App\Services\Sites\Contracts\SiteRuntimeProvisioner;

final class DockerRuntimeSiteProvisioner implements SiteRuntimeProvisioner
{
    public function __construct(
        private readonly DockerComposeArtifactBuilder $artifactBuilder,
        private readonly ContainerPublicationManager $publicationManager,
    ) {}

    public function runtimeProfile(): string
    {
        return 'docker_web';
    }

    public function provision(Site $site): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $runtime = is_array($meta['docker_runtime'] ?? null) ? $meta['docker_runtime'] : [];
        $meta['docker_runtime'] = array_merge($runtime, [
            'compose_yaml' => $this->artifactBuilder->build($site),
            'configured_at' => now()->toIso8601String(),
        ]);

        $site->forceFill(['meta' => $meta])->save();
        $this->publicationManager->provision($site->fresh());
    }

    public function readyResult(Site $site): array
    {
        $site->loadMissing(['domains', 'previewDomains']);

        return $this->publicationManager->readyResult($site);
    }
}
