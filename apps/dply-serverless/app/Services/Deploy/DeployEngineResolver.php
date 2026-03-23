<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Resolves which deploy engine runs a Serverless deploy. Phase D: single {@see ServerlessDeployEngine}.
 */
final class DeployEngineResolver
{
    public function __construct(
        private ServerlessDeployEngine $serverlessDeployEngine,
    ) {}

    public function default(): DeployEngine
    {
        return $this->serverlessDeployEngine;
    }
}
