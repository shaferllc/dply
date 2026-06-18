<?php

declare(strict_types=1);

namespace App\Modules\Scaffold\Jobs;

use App\Models\Site;
use App\Modules\Scaffold\Services\ScaffoldWordPressPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Worker that runs the WordPress scaffold pipeline against a Site
 * created in STATUS_SCAFFOLDING by Sites/Create::storeScaffold().
 */
class RunWordPressScaffoldJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public string $siteId) {}

    public function handle(ScaffoldWordPressPipeline $pipeline): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }
        if ($site->status !== Site::STATUS_SCAFFOLDING) {
            return;
        }

        $pipeline->run($site);
    }
}
