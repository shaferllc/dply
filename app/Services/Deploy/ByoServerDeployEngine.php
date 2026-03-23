<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;
use App\Services\Sites\SiteGitDeployer;

/**
 * BYO deploy engine: delegates to the existing git + SSH pipeline.
 */
final class ByoServerDeployEngine implements DeployEngine
{
    public function __construct(
        private SiteGitDeployer $gitDeployer,
    ) {}

    public function run(ByoDeployContext $context): array
    {
        $site = $context->project->site;
        if ($site === null) {
            throw new \RuntimeException('BYO project #'.$context->project->getKey().' has no site; cannot run VM deploy.');
        }

        return $this->gitDeployer->run($site);
    }
}
