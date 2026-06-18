<?php

declare(strict_types=1);

namespace App\Modules\Scaffold\Jobs;

use App\Models\Site;
use App\Modules\Scaffold\Services\ScaffoldLaravelPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Worker that runs the Laravel scaffold pipeline against a Site
 * created in STATUS_SCAFFOLDING by Sites/Create::storeScaffold().
 *
 * Dispatched at the end of storeScaffold() once the WordPress
 * counterpart (PR 6) lands and the route table grows; for now
 * this is the standalone Laravel-side worker.
 */
class RunLaravelScaffoldJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Outer wall-clock cap — composer + breeze + migrations can run long. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $siteId) {}

    public function handle(ScaffoldLaravelPipeline $pipeline): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }
        if ($site->status !== Site::STATUS_SCAFFOLDING) {
            // Idempotence — already scaffolded or moved on.
            return;
        }

        $pipeline->run($site);
    }
}
