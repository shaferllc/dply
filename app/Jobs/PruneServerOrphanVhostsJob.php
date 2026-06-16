<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Services\Sites\NginxOrphanVhostPruner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Remove orphaned dply-managed nginx vhosts on a server — `dply-*.conf` files
 * whose owning site no longer exists — and reload nginx. Streams progress into
 * the server's console drawer via the {@see WritesConsoleAction} machinery.
 *
 * This is the operator-triggered companion to the apply-time self-heal in
 * {@see \App\Services\Sites\SiteNginxProvisioner}: the provisioner only prunes
 * an orphan when it actively shadows the site being applied, whereas this sweeps
 * every orphan on the box in one pass.
 */
class PruneServerOrphanVhostsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    /** Auto-expire the unique lock so a lost run can't wedge it. */
    public int $uniqueFor = 300;

    public function __construct(
        public string $serverId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'console-action:vhost_prune:'.$this->serverId;
    }

    protected function consoleSubject(): Model
    {
        return Server::query()->findOrFail($this->serverId);
    }

    protected function consoleKind(): string
    {
        return 'vhost_prune';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(NginxOrphanVhostPruner $pruner): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('nginx', 'scanning '.($server->name ?: $server->id).' for orphaned vhosts');
            $result = $pruner->prune($server, $emit);

            if ($result['removed'] === []) {
                $emit->success('no orphaned vhosts found — nothing to prune', 'nginx');
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'nginx');
            $this->failConsoleAction($e->getMessage());

            Log::warning('PruneServerOrphanVhostsJob failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
