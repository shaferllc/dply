<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Insights\InsightHealthScoreService;
use App\Services\Insights\InsightRunCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSiteInsightsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public string $siteId
    ) {}

    public function handle(InsightRunCoordinator $coordinator, InsightHealthScoreService $healthScore): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if ($site === null || ! $site->server->isReady()) {
            return;
        }

        $coordinator->runForSite($site);
        $healthScore->computeAndStore($site->server);
    }
}
