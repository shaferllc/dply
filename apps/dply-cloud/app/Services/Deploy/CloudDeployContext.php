<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Input to {@see DeployEngine} for a Cloud application deploy.
 *
 * {@see RunCloudDeploymentJob} may pass {@see $providerConfig} with public project metadata
 * under `project` (never echo decrypted credentials in engine output).
 */
final readonly class CloudDeployContext
{
    /**
     * @param  string  $stack  e.g. php, rails (product convention).
     * @param  array<string, mixed>  $providerConfig
     */
    public function __construct(
        public string $applicationName,
        public string $stack,
        public string $gitRef = 'main',
        public string $trigger = 'api',
        public array $providerConfig = [],
    ) {}
}
