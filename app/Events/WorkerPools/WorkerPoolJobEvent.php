<?php

declare(strict_types=1);

namespace App\Events\WorkerPools;

use App\Http\Controllers\Api\WorkerPoolJobEventController;
use App\Listeners\ForwardWorkerPoolJobEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A single Horizon job lifecycle event forwarded from a worker pool box and
 * pushed over Reverb to the org's private channel, so the pool's Horizon
 * dashboard updates per-job in real time (no polling). Fired by the ingest
 * endpoint {@see WorkerPoolJobEventController} after a
 * box-side {@see ForwardWorkerPoolJobEvent} POSTs the event.
 *
 * Must be {@see ShouldBroadcastNow} (not ShouldBroadcast): queueing the
 * broadcast would put BroadcastEvent jobs on the same Redis queues Horizon
 * observes, which the box agent then re-forwards — a feedback loop that
 * floods `dply` / `dply:notify`.
 */
final class WorkerPoolJobEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array{name: string, queue: string, status: string, uuid: ?string, at: float}  $job
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $poolId,
        public readonly array $job,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('organization.'.$this->organizationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'worker-pool.job';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'pool_id' => $this->poolId,
            'job' => $this->job,
        ];
    }
}
