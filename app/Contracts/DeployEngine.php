<?php

namespace App\Contracts;

use App\Modules\Deploy\Services\DeployContext;

interface DeployEngine
{
    /**
     * Execute the deploy for the given context.
     *
     * @return array{output: string, sha: ?string}
     *
     * @throws \Throwable On deploy failure.
     */
    public function run(DeployContext $context): array;
}
