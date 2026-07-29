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
 * Tears down the backend resource for an cloud site. Idempotent
 * — safe to retry if the backend rejects (e.g. resource already
 * deleted out-of-band).
 */
class TeardownCloudSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }

        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);
        if ($backend !== null && $credential !== null) {
            $backend->teardown($site, $credential);
        }

        // Mark the site row inactive but don't delete it — keeps
        // audit history of what was deployed where, and lets the
        // operator see the failure path in the dashboard.
        $meta = $site->meta;
        $meta['container'] = array_merge($meta['container'] ?? [], [
            'torn_down_at' => now()->toIso8601String(),
        ]);
        $site->update([
            'status' => Site::STATUS_ERROR, // 'inactive' marker; could add STATUS_CONTAINER_TORN_DOWN later
            'meta' => $meta,
        ]);
    }
}
