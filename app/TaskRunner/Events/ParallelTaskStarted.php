<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when parallel task execution starts.
 */
class ParallelTaskStarted
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly array $tasks,
        public readonly string $executionId,
        public readonly string $startedAt,
        public readonly array $options
    ) {}
}
