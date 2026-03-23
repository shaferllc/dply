<?php

namespace App\Contracts;

use App\Services\Deploy\ServerlessDeployContext;

/**
 * Serverless product deploy seam (Phase D). Implementations call provider adapters / provisioners.
 */
interface DeployEngine
{
    /**
     * @return array{output: string, sha: ?string}
     */
    public function run(ServerlessDeployContext $context): array;
}
