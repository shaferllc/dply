<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Contracts\DeployEngine;

final class AwsLambdaDeployEngine implements DeployEngine
{
    public function __construct(
        private readonly AwsLambdaFunctionDeployer $functionDeployer,
    ) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function run(DeployContext $context): array
    {
        $result = $this->functionDeployer->deploy($context->site());

        return [
            'output' => $result['output'],
            'sha' => $result['revision_id'],
        ];
    }
}
