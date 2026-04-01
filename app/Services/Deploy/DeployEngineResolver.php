<?php

namespace App\Services\Deploy;

use App\Contracts\DeployEngine;
use App\Models\Project;
use App\Models\Site;

/**
 * Resolves which deploy engine handles a project. Phase B: always {@see ByoServerDeployEngine}.
 */
final class DeployEngineResolver
{
    public function __construct(
        private ByoServerDeployEngine $byoServerDeployEngine,
        private DigitalOceanFunctionsDeployEngine $digitalOceanFunctionsDeployEngine,
        private DockerDeployEngine $dockerDeployEngine,
        private KubernetesDeployEngine $kubernetesDeployEngine,
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

        if ($project->site?->server?->isDigitalOceanFunctionsHost()) {
            return $this->digitalOceanFunctionsDeployEngine;
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
