<?php

namespace App\Services\Deploy;

/**
 * Input to {@see DeployEngine} for a managed WordPress deploy (Phase F).
 *
 * {@see RunWordpressDeploymentJob} may pass {@see $providerConfig} with public project metadata
 * under `project` (never echo decrypted credentials in engine output).
 */
final readonly class WordpressDeployContext
{
    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function __construct(
        public string $applicationName,
        public string $phpVersion,
        public string $gitRef = 'main',
        public string $trigger = 'api',
        public array $providerConfig = [],
    ) {}
}
