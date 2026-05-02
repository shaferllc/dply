<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Servers\ServerStateUpdated;
use App\Models\Server;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Support\Facades\Log;

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
        $this->emitWebhook($server, 'server.created', $this->serverPayload($server), 'Server '.$server->name.' created');
    }

    public function updated(Server $server): void
    {
        if (! $server->wasChanged(self::BROADCAST_ON_UPDATE_FIELDS)) {
            return;
        }

        $this->broadcast($server, 'updated');
        $this->emitLifecycleWebhooks($server);
    }

    public function deleted(Server $server): void
    {
        $this->broadcast($server, 'deleted');
        $this->emitWebhook($server, 'server.deleted', [
            'id' => $server->id,
            'name' => $server->name,
        ], 'Server '.$server->name.' deleted');
    }

    /**
     * Translate observed field changes into discrete webhook events. Multiple events
     * can fire for one save (e.g. status went ready AND health flipped to critical).
     */
    private function emitLifecycleWebhooks(Server $server): void
    {
        $changes = $server->getChanges();

        if (array_key_exists('status', $changes) && $server->status === Server::STATUS_READY) {
            $this->emitWebhook($server, 'server.provisioned', $this->serverPayload($server), 'Server '.$server->name.' is ready');
        }

        if (array_key_exists('health_status', $changes)) {
            $this->emitWebhook($server, 'server.health.changed', [
                'previous' => $server->getOriginal('health_status'),
                'current' => $server->health_status,
                'last_check_at' => $server->last_health_check_at?->toIso8601String(),
                'server' => $this->serverPayload($server),
            ], 'Health: '.($server->health_status ?? 'unknown'));
        }

        if (array_key_exists('scheduled_deletion_at', $changes) && $server->scheduled_deletion_at !== null) {
            $this->emitWebhook($server, 'server.deletion.scheduled', [
                'scheduled_for' => $server->scheduled_deletion_at->toIso8601String(),
                'server' => $this->serverPayload($server),
            ], 'Server scheduled for deletion');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitWebhook(Server $server, string $eventKey, array $payload, ?string $summary = null): void
    {
        $organizationId = $server->organization_id;
        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        try {
            app(OutboundWebhookDispatcher::class)->dispatchForServer($eventKey, $server, $payload, $summary);
        } catch (\Throwable $e) {
            // Never let a webhook error break the model save path.
            Log::warning('outbound webhook dispatch failed', [
                'server_id' => $server->id,
                'event' => $eventKey,
                'exception' => $e->getMessage(),
            ]);
        }
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
