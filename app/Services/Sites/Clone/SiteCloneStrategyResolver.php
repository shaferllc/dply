<?php

namespace App\Services\Sites\Clone;

use App\Models\Site;

final class SiteCloneStrategyResolver
{
    public function __construct(
        private readonly VmSiteCloneStrategy $vm,
        private readonly ServerlessSiteCloneStrategy $serverless,
        private readonly ContainerSiteCloneStrategy $container,
    ) {}

    public function for(Site $source): VmSiteCloneStrategy|ServerlessSiteCloneStrategy|ContainerSiteCloneStrategy
    {
        if ($source->usesFunctionsRuntime()) {
            return $this->serverless;
        }

        if ($source->usesDockerRuntime() || $source->usesKubernetesRuntime()) {
            return $this->container;
        }

        return $this->vm;
    }
}
