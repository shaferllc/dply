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

    public function run(DeployContext $context): array
    {
        return $this->gitDeployer->run($context->site());
    }
}
