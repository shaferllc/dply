<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Jobs;

use App\Models\Site;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a Cloud site's autoscaling + health-check configuration
 * (meta.container.autoscaling / meta.container.health_check) into
 * its backend deployment spec and rolls a new deployment so the
 * change takes effect.
 *
 * Dispatched by ConfigureCloudAutoscaling / ConfigureCloudHealthCheck
 * after the config has been validated and persisted to the Site —
 * the backend rebuilds the autoscaling / health-check blocks from the
 * current config each call, so disabling a feature simply omits the
 * block on the next push.
 *
 * No-op when the site has no resolvable backend / credential, or has
 * not been provisioned on the backend yet (the config lands via the
 * next provision instead).
 */
class SyncCloudScalingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }

        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            return;
        }

        if (! $backend->supportsAutoscaling()) {
            return;
        }

        $backend->syncScaling($site, $credential);
    }
}
