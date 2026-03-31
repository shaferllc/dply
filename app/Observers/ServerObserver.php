<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Servers\ServerStateUpdated;
use App\Models\Server;

class ServerObserver
{
    /**
     * @var list<string>
     */
    private const BROADCAST_ON_UPDATE_FIELDS = [
        'status',
        'ip_address',
        'setup_status',
        'ssh_user',
        'name',
        'provider_id',
        'health_status',
        'last_health_check_at',
        'team_id',
        'scheduled_deletion_at',
    ];

    public function created(Server $server): void
    {
        $this->broadcast($server, 'created');
    }

    public function updated(Server $server): void
    {
        if (! $server->wasChanged(self::BROADCAST_ON_UPDATE_FIELDS)) {
            return;
        }

        $this->broadcast($server, 'updated');
    }

    public function deleted(Server $server): void
    {
        $this->broadcast($server, 'deleted');
    }

    private function broadcast(Server $server, string $action): void
    {
        $organizationId = $server->organization_id;
        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        $payload = $action === 'deleted' ? null : $this->serverPayload($server);

        ServerStateUpdated::dispatch($organizationId, $server->id, $action, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function serverPayload(Server $server): array
    {
        $provider = $server->provider;

        return [
            'id' => $server->id,
            'name' => $server->name,
            'status' => $server->status,
            'setup_status' => $server->setup_status,
            'ip_address' => $server->ip_address,
            'provider' => $provider instanceof \BackedEnum ? $provider->value : $provider,
            'team_id' => $server->team_id,
            'health_status' => $server->health_status,
            'scheduled_deletion_at' => $server->scheduled_deletion_at?->toIso8601String(),
        ];
    }
}
