<?php

namespace App\Services\Deploy;

/**
 * Input to {@see DeployEngine} for an Edge (git-native JS/static) deploy (Phase G).
 *
 * {@see RunEdgeDeploymentJob} may pass {@see $providerConfig} with public project metadata
 * under `project` (never echo decrypted credentials in engine output).
 */
final readonly class EdgeDeployContext
{
    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function __construct(
        public string $applicationName,
        public string $framework,
        public string $gitRef = 'main',
        public string $trigger = 'api',
        public array $providerConfig = [],
    ) {}
}
