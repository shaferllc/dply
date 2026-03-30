<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when parallel task execution fails.
 */
class ParallelTaskFailed
{
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly array $tasks,
        public readonly string $executionId,
        public readonly array $summary,
        public readonly string $startedAt,
        public readonly string $reason = ''
    ) {}
}
