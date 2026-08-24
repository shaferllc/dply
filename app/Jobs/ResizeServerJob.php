<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Modules\Notifications\Services\ServerResizeNotificationDispatcher;
use App\Services\Servers\ServerResizeOptions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Resize a server at its cloud provider, then reconcile the stored specs.
 *
 * The provider-specific sequence lives in the driver
 * ({@see \App\Services\Servers\Resize\ServerResizeDriver}) — DigitalOcean,
 * Hetzner and EC2 all stop and start the machine, Vultr reboots it in place.
 * This job owns what is the same everywhere: validation, the breadcrumb the
 * Settings card renders, the notifications, and reconciliation.
 *
 * Deliberately NOT retried. A resize is not idempotent from the middle: a retry
 * after a half-applied sequence could power-cycle a box that is already back up,
 * or re-issue a resize against the new size and error. On failure the server
 * keeps `meta['resize']['state' => 'failed']` and the operator decides.
 *
 * Unique per server so two resizes cannot interleave on the same machine.
 */
class ResizeServerJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Long enough for a disk-growing resize on a large machine. */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public Server $server,
        public string $targetSize,
        public bool $growDisk,
        public ?string $actorId = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(ServerResizeOptions $options, ServerResizeNotificationDispatcher $notifier): void
    {
        $server = $this->server->fresh();
        if (! $server) {
            return;
        }

        $actor = $this->actorId !== null ? User::find($this->actorId) : null;
        $fromSize = (string) ($server->size ?: '?');

        try {
            // Re-validate at execution time: the job may have sat in the queue
            // while the machine changed underneath it, and the disk-growth flag
            // is derived from the target rather than trusted from the caller.
            $driver = $options->driverFor($server)
                ?? throw new \RuntimeException('This server cannot be resized from dply.');
            $target = $options->resolveTarget($server, $this->targetSize);
        } catch (\Throwable $e) {
            $this->fail($server, $notifier, $fromSize, $e, $actor);

            return;
        }

        // Sent before anything is touched — this is the only warning the rest
        // of the org gets that their sites are about to drop.
        $notifier->started($server, $fromSize, $target['slug'], $driver->requiresPowerCycle(), $actor);

        try {
            $driver->execute($server, $target, function (string $state) use ($server): void {
                $this->markState($server, $state);
            });
        } catch (\Throwable $e) {
            $this->fail($server, $notifier, $fromSize, $e, $actor);

            return;
        }

        $this->markState($server, 'completed');
        $notifier->completed($server, $fromSize, $target['slug'], $actor);

        // Reuse the existing reconcile path: re-reads size/region/specs from
        // the provider and re-probes the box + inventory.
        SyncServerProviderSpecsJob::dispatch($server);
    }

    private function fail(
        Server $server,
        ServerResizeNotificationDispatcher $notifier,
        string $fromSize,
        \Throwable $e,
        ?User $actor,
    ): void {
        Log::error('server.resize_failed', [
            'server_id' => $server->id,
            'target_size' => $this->targetSize,
            'error' => $e->getMessage(),
        ]);

        $this->markState($server, 'failed', $e->getMessage());
        $notifier->failed($server, $fromSize, $this->targetSize, $e->getMessage(), $actor);

        // The machine may be powered off mid-sequence. Reconcile anyway so the
        // stored size reflects whatever actually landed.
        SyncServerProviderSpecsJob::dispatch($server);
    }

    /**
     * Breadcrumb on the server row so the Settings card can show progress
     * without a separate table.
     */
    private function markState(Server $server, string $state, ?string $error = null): void
    {
        $meta = $server->meta ?? [];
        $meta['resize'] = [
            'state' => $state,
            'target_size' => $this->targetSize,
            'grow_disk' => $this->growDisk,
            'error' => $error,
            'at' => now()->toIso8601String(),
        ];
        $server->update(['meta' => $meta]);
    }
}
