<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Insights\InsightHealthScoreService;
use App\Services\Insights\InsightRunCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunServerInsightsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public string $serverId
    ) {}

    public function handle(InsightRunCoordinator $coordinator, InsightHealthScoreService $healthScore): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null || ! $server->isReady()) {
            return;
        }

        $coordinator->runForServer($server);
        $healthScore->computeAndStore($server);
    }
}
