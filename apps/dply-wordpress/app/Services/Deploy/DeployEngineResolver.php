<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Resolves which deploy engine runs a WordPress deploy. Phase F: single {@see WordpressDeployEngine}.
 */
final class DeployEngineResolver
{
    public function __construct(
        private WordpressDeployEngine $wordpressDeployEngine,
    ) {}

    public function default(): DeployEngine
    {
        return $this->wordpressDeployEngine;
    }
}
