<?php

namespace App\Modules\Deploy\Services;

use App\Contracts\DeployEngine;
use App\Models\Project;
use App\Models\Site;

/**
 * Resolves which deploy engine handles a project: Docker, Kubernetes, or an
 * SSH-managed server. The DigitalOcean Functions and AWS Lambda engines were
 * deleted with the Serverless product; the container binding already passed
 * only three engines, so every deploy through here was fataling on an
 * ArgumentCountError until this matched it.
 */
final class DeployEngineResolver
{
    public function __construct(
        private DeployEngine $byoServerDeployEngine,
        private DeployEngine $dockerDeployEngine,
        private DeployEngine $kubernetesDeployEngine,
    ) {}

    public function forProject(Project $project): DeployEngine
    {
        $project->loadMissing('site.server');

        if ($project->site?->usesDockerRuntime()) {
            return $this->dockerDeployEngine;
        }

        if ($project->site?->usesKubernetesRuntime()) {
            return $this->kubernetesDeployEngine;
        }

        return $this->byoServerDeployEngine;
    }

    public function forSite(Site $site): DeployEngine
    {
        $site->loadMissing('project');
        if ($site->project === null) {
            throw new \RuntimeException('Site #'.$site->getKey().' has no project; run migrations or fix data.');
        }

        return $this->forProject($site->project);
    }
}
