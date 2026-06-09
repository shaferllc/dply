<?php

declare(strict_types=1);

namespace App\Events\Edge;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by EdgeLogIngestController on every Worker access record so
 * the dashboard's live log tail can render the request in real time
 * without polling the access_logs table.
 *
 * Broadcast on the existing `site.{siteId}` PrivateChannel so the
 * auth callback in routes/channels.php gates membership the same way
 * other site events do — no new auth surface to maintain.
 */
final class EdgeAccessLogReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $siteId,
        public readonly string $deploymentId,
        public readonly string $hostname,
        public readonly string $method,
        public readonly string $path,
        public readonly int $status,
        public readonly int $durationMs,
        public readonly int $bytes,
        public readonly string $cacheStatus,
        public readonly string $country,
        public readonly string $referrer,
        public readonly string $userAgent,
        public readonly string $occurredAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('site.'.$this->siteId)];
    }

    public function broadcastAs(): string
    {
        return 'edge.access-log';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deploymentId,
            'hostname' => $this->hostname,
            'method' => $this->method,
            'path' => $this->path,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'bytes_egress' => $this->bytes,
            'cache_status' => $this->cacheStatus,
            'country' => $this->country,
            'referrer' => $this->referrer,
            'user_agent' => $this->userAgent,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
