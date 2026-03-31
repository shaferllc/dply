<?php

namespace App\Contracts;

use App\Services\Deploy\CloudDeployContext;

/**
 * dply Cloud deploy seam (Phase E). Implementations run build/publish for long-lived apps.
 */
interface DeployEngine
{
    /**
     * @return array{output: string, sha: ?string}
     */
    public function run(CloudDeployContext $context): array;
}
