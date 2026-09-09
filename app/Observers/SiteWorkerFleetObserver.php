<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Site;
use App\Services\WorkerPools\SiteWorkerFleetEnvSync;

/**
 * When the origin site's env changes, push the replica-transformed copy to
 * every hidden fleet replica. Replica saves return early so this cannot loop.
 */
class SiteWorkerFleetObserver
{
    public function updated(Site $site): void
    {
        if (! $site->wasChanged('env_file_content')) {
            return;
        }
        if ($site->isFleetReplica()) {
            return;
        }

        app(SiteWorkerFleetEnvSync::class)->syncFromOrigin($site);
    }
}
