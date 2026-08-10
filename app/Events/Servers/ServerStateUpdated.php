<?php

declare(strict_types=1);

namespace App\Events\Servers;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed over Reverb when a server row changes so the servers UI can refresh without polling.
 */
final class ServerStateUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  ?array<string, mixed>  $server
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $serverId,
        public readonly string $action,
        public readonly ?array $server = null,
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
        return 'server.state.updated';
    }

    /**
     * Fan-out lane. A fleet-wide state sweep can queue dozens of these at once;
     * ahead of a deploy job they add up to a visibly stalled progress bar.
     */
    public function broadcastQueue(): string
    {
        return (string) config('dply.queues.background');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'server_id' => $this->serverId,
            'action' => $this->action,
            'server' => $this->server,
        ];
    }
}
