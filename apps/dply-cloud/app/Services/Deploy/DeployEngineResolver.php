<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Resolves which deploy engine runs a Cloud deploy. Phase E: single {@see CloudDeployEngine}.
 */
final class DeployEngineResolver
{
    public function __construct(
        private CloudDeployEngine $cloudDeployEngine,
    ) {}

    public function default(): DeployEngine
    {
        return $this->cloudDeployEngine;
    }
}
