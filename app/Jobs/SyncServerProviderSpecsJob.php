<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerProviderSpecSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queued post-resize verification: re-read the server's hardware facts from
 * its cloud provider (via {@see ServerProviderSpecSync}), then kick the on-box
 * verification sweep (reachability probe + inventory refresh) so live stats
 * catch up in the same pass. Dispatched from the Settings provider card's
 * "Verify with provider" action.
 *
 * Billing is NOT affected by a stale size — the tier is classified from live
 * agent metric snapshots — but the stored facts (hero SIZE tile, cost card
 * catalog pricing, clone flow) should agree with reality.
 */
class SyncServerProviderSpecsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public Server $server
    ) {}

    public function handle(ServerProviderSpecSync $specSync): void
    {
        $server = $this->server->fresh();
        if (! $server) {
            return;
        }

        try {
            $specSync->sync($server);
        } catch (\Throwable $e) {
            Log::warning('server.provider_spec_sync_failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
            $specSync->recordFailure($server, $e);

            return;
        }

        // Provider facts are current — now verify the box itself: reachability
        // plus the SSH inventory probe, so on-box stats (memory, disk, services)
        // reflect the resized machine without waiting for the next cadence.
        if ($server->isReady() && ! empty($server->ip_address)) {
            CheckServerHealthJob::dispatch($server);
            RefreshServerInventoryJob::dispatch((string) $server->id);
        }
    }
}
