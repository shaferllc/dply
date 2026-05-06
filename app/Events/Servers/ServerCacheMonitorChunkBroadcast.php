<?php

declare(strict_types=1);

namespace App\Events\Servers;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One chunk of `redis-cli MONITOR` output streamed during a bounded tail
 * window. Mirrors `ServerCronRunOutputChunkBroadcast` so the workspace can
 * subscribe to the same `server.{serverId}` private channel and route
 * payloads by `runId`.
 */
final class ServerCacheMonitorChunkBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $serverId,
        public readonly string $runId,
        public readonly string $chunk,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('server.'.$this->serverId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'server.cache.monitor.chunk';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'run_id' => $this->runId,
            'chunk' => $this->chunk,
        ];
    }
}
