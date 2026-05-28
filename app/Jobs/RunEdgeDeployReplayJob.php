<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EdgeDeployReplay;
use App\Services\Edge\EdgeDeployReplayRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunEdgeDeployReplayJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $replayId,
    ) {}

    public function handle(EdgeDeployReplayRunner $runner): void
    {
        $replay = EdgeDeployReplay::query()->find($this->replayId);
        if ($replay === null) {
            return;
        }

        $runner->execute($replay);
    }
}
