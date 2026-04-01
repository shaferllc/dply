<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

final class DigitalOceanFunctionsDeployEngine implements DeployEngine
{
    public function __construct(
        private readonly DigitalOceanFunctionsActionDeployer $actionDeployer,
    ) {}

    public function run(ByoDeployContext $context): array
    {
        $site = $context->project->site;
        if ($site === null) {
            throw new \RuntimeException('Project has no site; cannot run a DigitalOcean Functions deploy.');
        }

        $result = $this->actionDeployer->deploy($site);

        return [
            'output' => $result['output'],
            'sha' => $result['revision_id'],
        ];
    }
}
