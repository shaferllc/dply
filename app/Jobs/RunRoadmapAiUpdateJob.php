<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Roadmap\RoadmapAiUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued entry point for the post-deploy AI roadmap update. The command runs it
 * synchronously on the deploy box (deterministic, no reliance on a worker
 * picking it up), but this job exists for the scheduler / on-demand dispatch.
 *
 * Unique-per-target-commit so two overlapping deploys can't double-apply the
 * same range.
 */
class RunRoadmapAiUpdateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public ?string $deployedCommit = null) {}

    public function uniqueId(): string
    {
        return 'roadmap-ai-update:'.($this->deployedCommit ?: 'head');
    }

    public function handle(RoadmapAiUpdater $updater): void
    {
        $updater->run($this->deployedCommit);
    }
}
