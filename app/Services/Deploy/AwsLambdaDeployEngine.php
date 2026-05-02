<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

final class AwsLambdaDeployEngine implements DeployEngine
{
    public function __construct(
        private readonly AwsLambdaFunctionDeployer $functionDeployer,
    ) {}

    public function run(DeployContext $context): array
    {
        $result = $this->functionDeployer->deploy($context->site());

        return [
            'output' => $result['output'],
            'sha' => $result['revision_id'],
        ];
    }
}
