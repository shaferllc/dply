<?php

declare(strict_types=1);

namespace App\Events\Servers;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Final payload for a bounded MONITOR tail window. Carries success state
 * and the line count so the UI can stop its loading indicator and roll up
 * a summary even if the operator missed individual chunks.
 */
final class ServerCacheMonitorCompletedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $serverId,
        public readonly string $runId,
        public readonly bool $success,
        public readonly int $lineCount,
        public readonly ?string $error,
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
        return 'server.cache.monitor.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'run_id' => $this->runId,
            'success' => $this->success,
            'line_count' => $this->lineCount,
            'error' => $this->error,
        ];
    }
}
