<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;
use App\Models\Project;
use App\Models\Site;

/**
 * Input for {@see DeployEngine} in the BYO product (VM / SSH).
 *
 * The control-plane unit is {@see Project}; BYO engines resolve the linked {@see Site} for SSH/git.
 */
final class ByoDeployContext
{
    public function __construct(
        public readonly Project $project,
        public readonly string $trigger,
        public readonly ?string $apiIdempotencyHash = null,
        public readonly ?int $auditUserId = null,
    ) {}
}
