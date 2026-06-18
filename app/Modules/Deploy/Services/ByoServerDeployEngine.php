<?php

namespace App\Modules\Deploy\Services;

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

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function run(DeployContext $context): array
    {
        return $this->gitDeployer->run($context->site(), $context->deployment, $context->resume);
    }
}
