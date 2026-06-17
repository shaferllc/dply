<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

final class DigitalOceanFunctionsDeployEngine implements DeployEngine
{
    public function __construct(
        private readonly DigitalOceanFunctionsActionDeployer $actionDeployer,
    ) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function run(DeployContext $context): array
    {
        $result = $this->actionDeployer->deploy($context->site());

        return [
            'output' => $result['output'],
            'sha' => $result['revision_id'],
        ];
    }
}
