<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;
use App\Models\ServerlessProject;

/**
 * Input for {@see DeployEngine} in the Serverless app.
 *
 * {@see RunServerlessFunctionDeploymentJob} fills providerConfig from the linked {@see ServerlessProject}
 * when present: public project metadata under project, decrypted credentials under credentials (never echoed in provisioner_output keys).
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
