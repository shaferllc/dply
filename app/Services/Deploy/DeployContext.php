<?php

namespace App\Services\Deploy;

use App\Models\Project;
use App\Models\Site;

final class DeployContext
{
    public function __construct(
        public readonly Project $project,
        public readonly string $trigger,
        public readonly ?string $apiIdempotencyHash = null,
        public readonly ?string $auditUserId = null,
    ) {}

    public function site(): Site
    {
        $site = $this->project->site;
        if (! $site instanceof Site) {
            throw new \RuntimeException('Project has no site; cannot run deployment.');
        }

        return $site;
    }
}
