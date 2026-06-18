<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when parallel task execution completes successfully.
 */
class ParallelTaskCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     * @param  array<string, mixed> $summary
     * @param  array<string, mixed> $tasks
     */
    public function __construct(
        public readonly array $tasks,
        public readonly string $executionId,
        public readonly array $summary,
        public readonly string $startedAt
    ) {}
}
