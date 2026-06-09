<?php

namespace App\Services\Deploy;

use App\Models\Project;
use App\Models\Site;
use App\Models\SiteDeployment;

final class DeployContext
{
    public function __construct(
        public readonly Project $project,
        public readonly string $trigger,
        public readonly ?string $apiIdempotencyHash = null,
        public readonly ?string $auditUserId = null,
        // The in-flight deployment row, when one exists. Engines that record
        // per-phase progress (the BYO/VM path) write to it as they run so the
        // Deploy-tab timeline updates live; other engines ignore it.
        public readonly ?SiteDeployment $deployment = null,
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
