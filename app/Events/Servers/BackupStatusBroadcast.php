<?php

declare(strict_types=1);

namespace App\Events\Servers;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed over the dply realtime relay when a backup finishes (success or
 * failure) so the operator who triggered it gets a transient, app-wide toast —
 * even while they're on another page. Broadcast on the org channel (already
 * subscribed app-wide in bootstrap.js); the front-end filters to the triggering
 * user via {@see $userId} so other admins aren't spammed.
 */
final class BackupStatusBroadcast implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $userId,
        public readonly string $message,
        public readonly string $type,
        public readonly ?string $serverId = null,
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
        return 'backup.status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'message' => $this->message,
            'type' => $this->type,
            'server_id' => $this->serverId,
        ];
    }
}
