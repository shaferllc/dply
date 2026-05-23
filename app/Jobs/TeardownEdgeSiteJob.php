<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Edge\EdgeRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TeardownEdgeSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->usesEdgeRuntime()) {
            return;
        }

        $backend = EdgeRouter::backendFor($site);
        $site->load('edgeDeployments');
        $backend?->unpublish($site);

        $site->edgeDeployments()->delete();
        $site->delete();
    }
}
