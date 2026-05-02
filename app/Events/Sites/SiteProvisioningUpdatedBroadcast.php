<?php

declare(strict_types=1);

namespace App\Events\Sites;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class SiteProvisioningUpdatedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $serverId,
        public readonly string $siteId,
        public readonly string $status,
        public readonly ?string $provisioningState,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('site.'.$this->siteId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'site.provisioning.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'site_id' => $this->siteId,
            'status' => $this->status,
            'provisioning_state' => $this->provisioningState,
        ];
    }
}
