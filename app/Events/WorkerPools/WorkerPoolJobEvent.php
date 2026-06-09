<?php

declare(strict_types=1);

namespace App\Events\WorkerPools;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A single Horizon job lifecycle event forwarded from a worker pool box and
 * pushed over Reverb to the org's private channel, so the pool's Horizon
 * dashboard updates per-job in real time (no polling). Fired by the ingest
 * endpoint {@see \App\Http\Controllers\Api\WorkerPoolJobEventController} after a
 * box-side {@see \App\Listeners\ForwardWorkerPoolJobEvent} POSTs the event.
 *
 * NOT ShouldQueue: queueing the broadcast would route it through the very queue
 * we're observing and add latency — these are fire-and-forget UI pushes.
 */
final class WorkerPoolJobEvent implements ShouldBroadcast
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
