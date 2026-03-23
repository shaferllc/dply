<?php

namespace App\Contracts;

use App\Services\Deploy\ByoDeployContext;
use App\Services\Sites\SiteGitDeployer;

interface DeployEngine
{
    /**
     * Execute the deploy for the given context (BYO: git/SSH pipeline).
     *
     * @return array{output: string, sha: ?string}
     *
     * @throws \Throwable On deploy failure (same contract as {@see SiteGitDeployer::run}).
     */
    public function run(ByoDeployContext $context): array;
}
