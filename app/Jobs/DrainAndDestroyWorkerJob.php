<?php

namespace App\Jobs;

use App\Actions\Servers\DeleteServerAction;
use App\Models\Server;
use App\Models\User;
use App\Services\SshConnection;
use App\Services\WorkerPools\WorkerPoolExposureApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Gracefully drains a pool replica's queue workers, then tears the box down.
 *
 * Drain = stop the site worker units so they finish in-flight jobs and stop
 * pulling new ones (best-effort over SSH; queue retry/visibility-timeout covers
 * anything cut short). Destroy = the standard {@see DeleteServerAction} which
 * removes provider resources and the server row.
 *
 * Guard: refuses to destroy a pool primary (the manager already blocks this,
 * but the job double-checks since destruction is irreversible).
 */
class DrainAndDestroyWorkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $serverId,
        public ?string $actorId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(DeleteServerAction $deleteServer, WorkerPoolExposureApplier $exposure): void
    {
        $server = Server::find($this->serverId);
        if (! $server instanceof Server) {
            return;
        }

        if ($server->isPoolPrimary()) {
            Log::warning('worker-pool: refusing to drain+destroy the pool primary', [
                'server_id' => $server->id,
            ]);

            return;
        }

        // Revoke this worker's backend firewall grants before it's destroyed so
        // we don't leave dangling allow rules for a recycled public IP.
        $pool = $server->workerPool;
        if ($pool !== null) {
            try {
                $exposure->pruneForMember($pool, $server);
            } catch (\Throwable $e) {
                Log::info('worker-pool: exposure prune failed (continuing)', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->drain($server);

        $actor = $this->actorId !== null
            ? User::find($this->actorId)
            : null;

        $deleteServer->execute(
            $server,
            $actor,
            ['reason' => 'worker_pool_scale_down', 'worker_pool_id' => (string) ($server->worker_pool_id ?? '')],
        );
    }

    /**
     * Best-effort: stop all dply site worker units so in-flight jobs finish and
     * no new ones are picked up before the box is destroyed.
     */
    private function drain(Server $server): void
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        try {
            $ssh = new SshConnection($server);
            // systemctl accepts unit globs; stop every per-site dply unit. The
            // 90s drain budget lets graceful stop finish in-flight jobs.
            $ssh->exec("sudo systemctl stop 'dply-site-*.service' 2>/dev/null || true", 90);
        } catch (\Throwable $e) {
            Log::info('worker-pool: drain best-effort stop failed (continuing to destroy)', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
