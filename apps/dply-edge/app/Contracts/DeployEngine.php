<?php

namespace App\Contracts;

use App\Services\Deploy\EdgeDeployContext;

/**
 * dply Edge deploy seam (Phase G). Implementations run framework builds, static/edge publish, and PR previews.
 */
interface DeployEngine
{
    /**
     * @return array{output: string, sha: ?string}
     */
    public function run(EdgeDeployContext $context): array;
}
