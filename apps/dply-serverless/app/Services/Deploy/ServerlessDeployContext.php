<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;

/**
 * Input for {@see DeployEngine} in the Serverless app.
 *
 * Until `projects` / deployments exist here, this carries function identity + artifact path only.
 */
final class ServerlessDeployContext
{
    /**
     * @param  array<string, mixed>  $providerConfig
     */
    public function __construct(
        public readonly string $functionName,
        public readonly string $runtime,
        public readonly string $artifactPath,
        public readonly string $trigger = 'manual',
        public readonly ?string $apiIdempotencyHash = null,
        public readonly ?int $auditUserId = null,
        public readonly array $providerConfig = [],
    ) {}
}
