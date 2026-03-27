<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Resolves which deploy engine runs an Edge deploy. Phase G: single {@see EdgeDeployEngine}.
 */
final class DeployEngineResolver
{
    public function __construct(
        private EdgeDeployEngine $edgeDeployEngine,
    ) {}

    public function default(): DeployEngine
    {
        return $this->edgeDeployEngine;
    }
}
