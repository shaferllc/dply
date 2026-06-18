<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Mark an in-flight Edge deployment as failed and queue a fresh build.
 * Used by the "Restart build" affordance on the build journey card when
 * the queue worker / Docker process has hung and the only recovery path
 * is to abandon the row + start over.
 *
 * Pure DB mutation on the stuck row — we cannot reach into a running
 * queue job to kill it cooperatively. If the old job *does* eventually
 * complete, the new deployment will already have superseded it in the
 * host map by the time it tries to publish.
 */
class CancelStuckEdgeDeployment
{
    public function handle(Site $site, EdgeDeployment $deployment, ?string $reason = null): EdgeDeployment
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \RuntimeException('Site is not an Edge delivery site.');
        }

        if ($deployment->site_id !== $site->id) {
            throw new \RuntimeException('Deployment does not belong to this site.');
        }

        if (! in_array($deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true)) {
            throw new \RuntimeException('Only in-flight deployments can be cancelled.');
        }

        $deployment->update([
            'status' => EdgeDeployment::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason ?? 'Cancelled by operator — build appeared stuck.',
        ]);

        return (new RedeployEdgeSite)->handle($site, $deployment->git_commit);
    }
}
