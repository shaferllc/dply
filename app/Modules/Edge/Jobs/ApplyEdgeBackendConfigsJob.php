<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Jobs\SyncServerSystemdServicesJob;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Services\Sites\SiteEdgeBackendProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Server-wide repair: rebuild Caddy *-backend / *-tls configs and the active
 * edge proxy routing file (Envoy / HAProxy / Traefik) for every site.
 */
class ApplyEdgeBackendConfigsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $serverId,
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'edge_backend_apply_'.$this->serverId;
    }

    public function uniqueFor(): int
    {
        return 120;
    }

    protected function consoleSubject(): Model
    {
        return Server::findOrFail($this->serverId);
    }

    protected function consoleKind(): string
    {
        return 'edge_proxy';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteEdgeBackendProvisioner $provisioner): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $edgeProxy = $server->edgeProxy();
        if (! is_string($edgeProxy)) {
            return;
        }

        $emitter = $this->beginConsoleAction();

        try {
            $emitter->info(sprintf('[sync] rebuilding edge backends + %s routing…', $edgeProxy));
            $provisioner->syncAllForServer($server, $emitter);

            SyncServerSystemdServicesJob::dispatch($server->id);

            $emitter->info('Done.');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emitter->error('Edge backend sync failed: '.$e->getMessage());
            $this->failConsoleAction($e->getMessage());
        }
    }
}
