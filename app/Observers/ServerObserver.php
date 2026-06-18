<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Servers\ServerStateUpdated;
use App\Modules\Imports\Jobs\RunMigrationStepJob;
use App\Jobs\SyncOrganizationBillingJob;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\Server;
use App\Modules\Imports\Services\MigrationPlanner;
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
        $this->syncBillingOnReadyTransition($server);
    }

    public function deleted(Server $server): void
    {
        $this->broadcast($server, 'deleted');
        $this->emitWebhook($server, 'server.deleted', [
            'id' => $server->id,
            'name' => $server->name,
        ], 'Server '.$server->name.' deleted');
        $this->dispatchBillingSync($server->organization_id);
    }

    /**
     * Dispatch a billing sync whenever a server enters or leaves status=ready —
     * the only transitions that change whether a server is *billable* (which
     * is gated to ready-and-healthy in OrganizationBillingStateComputer).
     */
    private function syncBillingOnReadyTransition(Server $server): void
    {
        $changes = $server->getChanges();
        if (! array_key_exists('status', $changes)) {
            return;
        }

        $original = $server->getOriginal('status');
        $current = $server->status;

        $movedIntoReady = $current === Server::STATUS_READY && $original !== Server::STATUS_READY;
        $movedOutOfReady = $original === Server::STATUS_READY && $current !== Server::STATUS_READY;

        if ($movedIntoReady || $movedOutOfReady) {
            $this->dispatchBillingSync($server->organization_id);
        }
    }

    private function dispatchBillingSync(?string $organizationId): void
    {
        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        SyncOrganizationBillingJob::dispatch($organizationId, 'server_lifecycle');
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
            $this->resumeWaitingImportMigrations($server);
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
     * When a Server transitions to READY, find any ImportServerMigration whose
     * target_server_id is this server and dispatch the next runnable step —
     * SSH-dependent handlers throw WaitForTargetServerException when called
     * before the server is ready, and that's what we're resolving here.
     */
    private function resumeWaitingImportMigrations(Server $server): void
    {
        try {
            $migrations = ImportServerMigration::query()
                ->where('target_server_id', $server->id)
                ->whereNotIn('status', [
                    ImportServerMigration::STATUS_COMPLETED,
                    ImportServerMigration::STATUS_PARTIAL,
                    ImportServerMigration::STATUS_ABORTED,
                    ImportServerMigration::STATUS_CUTOVER_FAILED,
                ])
                ->get();

            foreach ($migrations as $migration) {
                $next = ImportMigrationStep::query()
                    ->where('import_server_migration_id', $migration->id)
                    ->where('status', ImportMigrationStep::STATUS_PENDING)
                    ->whereNotIn('step_key', MigrationPlanner::CUTOVER_STEPS)
                    ->orderBy('sequence')
                    ->first();

                if ($next !== null) {
                    RunMigrationStepJob::dispatch($next->id);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('failed to resume import migrations on server ready', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
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
        return [
            'id' => $server->id,
            'name' => $server->name,
            'status' => $server->status,
            'setup_status' => $server->setup_status,
            'ip_address' => $server->ip_address,
            'provider' => $server->provider->value,
            'team_id' => $server->team_id,
            'health_status' => $server->health_status,
            'scheduled_deletion_at' => $server->scheduled_deletion_at?->toIso8601String(),
        ];
    }
}
