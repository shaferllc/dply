<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;
use App\Contracts\ServerlessFunctionProvisioner;

/**
 * Default Serverless engine: publishes via {@see ServerlessFunctionProvisioner} (stub or cloud adapter).
 */
final class ServerlessDeployEngine implements DeployEngine
{
    public function __construct(
        private ServerlessFunctionProvisioner $provisioner,
    ) {}

    public function run(ServerlessDeployContext $context): array
    {
        $deploy = $this->provisioner->deployFunction(
            $context->functionName,
            $context->runtime,
            $context->artifactPath,
            $context->providerConfig,
        );

        return [
            'output' => json_encode($deploy, JSON_THROW_ON_ERROR),
            'sha' => $deploy['revision_id'] ?? null,
        ];
    }
}
