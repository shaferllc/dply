<?php

namespace App\Services\Sites\Clone;

use App\Models\Site;

final class SiteCloneStrategyResolver
{
    public function __construct(
        private readonly VmSiteCloneStrategy $vm,
        private readonly ContainerSiteCloneStrategy $container,
    ) {}

    public function for(Site $source): VmSiteCloneStrategy|ContainerSiteCloneStrategy
    {
        // ServerlessSiteCloneStrategy went with the serverless surface
        // (remove-cloud-edge-serverless); a function site now clones through
        // the VM strategy like anything else.
        if ($source->usesDockerRuntime() || $source->usesKubernetesRuntime()) {
            return $this->container;
        }

        return $this->vm;
    }
}
