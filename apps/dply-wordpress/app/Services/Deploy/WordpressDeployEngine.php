<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;
use App\Contracts\HostedWordpressProvisioner;

/**
 * Managed WordPress deploy: delegates to {@see HostedWordpressProvisioner} (hosted-only, ADR-007).
 */
final class WordpressDeployEngine implements DeployEngine
{
    public function __construct(
        private HostedWordpressProvisioner $provisioner,
    ) {}

    public function run(WordpressDeployContext $context): array
    {
        $result = $this->provisioner->deploy($context);

        return [
            'output' => $result['output'],
            'sha' => $result['revision_id'],
        ];
    }
}
