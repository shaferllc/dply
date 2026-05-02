<?php

namespace App\Services\Sites;

use App\Models\Site;

class SiteWebserverConfigApplier
{
    public function __construct(
        private readonly SiteWebserverProvisionerRegistry $registry,
    ) {}

    public function apply(Site $site): string
    {
        $site->loadMissing('server');

        if ($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime()) {
            throw new \RuntimeException('This site runtime does not use managed VM webserver config.');
        }

        if (! $site->server || ! $site->server->hostCapabilities()->supportsSsh()) {
            throw new \RuntimeException('This host runtime does not support managed webserver config.');
        }

        return $this->registry->for($site->webserver())->provision($site);
    }
}
