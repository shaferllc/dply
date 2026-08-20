<?php

namespace App\Modules\Deploy\Services;

use App\Contracts\DeployEngine;

final class DigitalOceanFunctionsDeployEngine implements DeployEngine
{
    public function __construct(
        private readonly DigitalOceanFunctionsActionDeployer $actionDeployer,
    ) {}

    /** @return array<string, mixed> */
    public function run(DeployContext $context): array
    {
        app(ServerlessDeployProgress::class)->seed($context->site());

        $result = $this->actionDeployer->deploy($context->site());

        return [
            'output' => $result['output'],
            'sha' => $result['revision_id'],
        ];
    }
}
