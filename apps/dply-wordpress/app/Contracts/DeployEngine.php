<?php

namespace App\Contracts;

use App\Services\Deploy\WordpressDeployContext;

/**
 * dply WordPress deploy seam (Phase F). Implementations run managed WordPress lifecycle (deploy, updates, backups).
 */
interface DeployEngine
{
    /**
     * @return array{output: string, sha: ?string}
     */
    public function run(WordpressDeployContext $context): array;
}
