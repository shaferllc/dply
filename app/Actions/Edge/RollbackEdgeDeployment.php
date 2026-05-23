<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Jobs\PublishEdgeDeploymentJob;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Support\Edge\FakeEdgeProvision;

class RollbackEdgeDeployment
{
    public function handle(Site $site, string $deploymentId): EdgeDeployment
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \RuntimeException('Site is not an Edge site.');
        }

        $deployment = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->whereIn('status', [
                EdgeDeployment::STATUS_LIVE,
                EdgeDeployment::STATUS_SUPERSEDED,
            ])
            ->find($deploymentId);

        if ($deployment === null) {
            throw new \RuntimeException('Deployment not found or not eligible for rollback.');
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if ($activeId === $deployment->id) {
            throw new \RuntimeException('That deployment is already live.');
        }

        $artifactDir = $this->resolveArtifactDir($deployment);
        if ($artifactDir === null || ! is_dir($artifactDir)) {
            throw new \RuntimeException('Stored build artifacts for that deployment are no longer available.');
        }

        PublishEdgeDeploymentJob::dispatch($deployment->id, $artifactDir);

        return $deployment;
    }

    private function resolveArtifactDir(EdgeDeployment $deployment): ?string
    {
        if (! FakeEdgeProvision::enabled()) {
            return null;
        }

        $path = rtrim(FakeEdgeProvision::storageRoot(), '/').'/'.trim($deployment->storage_prefix, '/');
        if (! is_dir($path)) {
            return null;
        }

        return $path;
    }
}
