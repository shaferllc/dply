<?php

namespace App\Contracts;

use App\Services\Deploy\WordpressDeployContext;

/**
 * Internal provisioning layer for hosted WordPress (ADR-007). No BYO SSH.
 */
interface HostedWordpressProvisioner
{
    /**
     * Run a deploy against dply-operated capacity (HTTP/SDK/local stub).
     *
     * @return array{output: string, revision_id: string}
     */
    public function deploy(WordpressDeployContext $context): array;
}
