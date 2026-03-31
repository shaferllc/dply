<?php

declare(strict_types=1);

namespace App\Events\Servers;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushes the latest log viewer payload over Reverb so other tabs (same server + log source)
 * can update without each running SSH tail.
 */
final class ServerWorkspaceLogSnapshotBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $serverId,
        public readonly string $logKey,
        public readonly ?string $remoteLogRaw,
        public readonly ?string $remoteLogError,
        public readonly string $logLastFetchedAt,
        public readonly bool $logLastFetchTruncated,
        public readonly int $logLastFetchRawBytes,
        public readonly bool $broadcastPayloadTruncated,
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
        return 'server.workspace.log.snapshot';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'log_key' => $this->logKey,
            'remote_log_raw' => $this->remoteLogRaw,
            'remote_log_error' => $this->remoteLogError,
            'log_last_fetched_at' => $this->logLastFetchedAt,
            'log_last_fetch_truncated' => $this->logLastFetchTruncated,
            'log_last_fetch_raw_bytes' => $this->logLastFetchRawBytes,
            'broadcast_payload_truncated' => $this->broadcastPayloadTruncated,
        ];
    }
}
