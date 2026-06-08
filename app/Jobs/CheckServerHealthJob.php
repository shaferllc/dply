<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerHealthNotifier;
use App\Services\Servers\ServerHealthProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckServerHealthJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 15;

    public function __construct(
        public Server $server
    ) {}

    public function handle(ServerHealthProbe $probe, ServerHealthNotifier $healthNotifier): void
    {
        $server = $this->server->fresh();
        if (! $server || $server->status !== Server::STATUS_READY || empty($server->ip_address)) {
            return;
        }

        $previousHealth = $server->health_status;
        $result = $probe->probe($server);

        $server->update([
            'last_health_check_at' => now(),
            'health_status' => $result['ok'] ? Server::HEALTH_REACHABLE : Server::HEALTH_UNREACHABLE,
        ]);

        // Evaluate the health cockpit (DB-only rollup) and fire transition-aware
        // notifications when the overall posture worsens or recovers. Isolated in
        // its own try so a cockpit hiccup never fails the reachability probe.
        if ($server->isVmHost()) {
            try {
                $healthNotifier->evaluateAndNotify($server);
            } catch (\Throwable $e) {
                Log::warning('health.notify_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);
            }
        }

        // First time this server reports reachable (post-provision OR
        // after a flaky window cleared), or whenever the systemd
        // inventory has never been recorded — refresh System services
        // so the workspace card isn't empty when the operator lands on
        // it. The job is unique-per-server with a 120s window, so
        // duplicate dispatches across overlapping triggers no-op.
        $becameReachable = $result['ok'] && $previousHealth !== Server::HEALTH_REACHABLE;
        $inventoryNeverRan = empty($server->meta['systemd_inventory_last_at'] ?? null);

        if ($result['ok'] && $server->isVmHost() && ($becameReachable || $inventoryNeverRan)) {
            SyncServerSystemdServicesJob::dispatch((string) $server->id);
        }
    }
}
