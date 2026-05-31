<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Services\Deploy\SiteDeployPipelineManager;
use Illuminate\Support\Str;

/**
 * Picks which deploy pipeline runs for a given Git branch (without changing the UI default).
 */
final class DeployPipelineBranchResolver
{
    public function resolveForBranch(Site $site, ?string $branch = null): SiteDeployPipeline
    {
        $site->loadMissing(['deployPipelines', 'activeDeployPipeline']);
        $branch = trim((string) ($branch ?? $site->git_branch ?? 'main'));
        if ($branch === '') {
            $branch = 'main';
        }

        $pipelines = $site->deployPipelines->sortBy('sort_order')->values();

        foreach ($pipelines as $pipeline) {
            if ($this->matchesBranch($pipeline, $branch)) {
                return $pipeline;
            }
        }

        if ($site->activeDeployPipeline) {
            return $site->activeDeployPipeline;
        }

        $default = $pipelines->firstWhere('is_default', true) ?? $pipelines->first();

        if ($default instanceof SiteDeployPipeline) {
            return $default;
        }

        return app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    }

    /**
     * Point in-memory deploy relations at the pipeline for this branch (does not persist active id).
     */
    public function applyForDeploy(Site $site, ?string $branch = null): SiteDeployPipeline
    {
        $pipeline = $this->resolveForBranch($site, $branch);
        $site->setAttribute('active_deploy_pipeline_id', $pipeline->id);
        $site->unsetRelation('deploySteps');
        $site->unsetRelation('deployHooks');

        return $pipeline;
    }

    private function matchesBranch(SiteDeployPipeline $pipeline, string $branch): bool
    {
        $patterns = $pipeline->deploy_branches;
        if (! is_array($patterns) || $patterns === []) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            if ($pattern === $branch) {
                return true;
            }
            if (Str::is($pattern, $branch)) {
                return true;
            }
        }

        return false;
    }
}
