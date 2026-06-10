<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Servers\DeleteServerAction;
use App\Models\SiteBackend;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tears down a single web backend: deletes its child Site row, destroys the
 * backend server at the provider (via {@see DeleteServerAction}), then removes
 * the {@see SiteBackend} row. Dispatched by SiteBackendManager::removeBackend
 * after the backend has been drained from the balancer. Never touches the
 * primary backend. See docs/MULTI_BACKEND_SITES.md.
 */
class DestroySiteBackendJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $backendId,
        public ?string $actorId = null,
    ) {}

    public function handle(DeleteServerAction $deleteServer): void
    {
        $backend = SiteBackend::query()->with(['server', 'backendSite'])->find($this->backendId);
        if (! $backend instanceof SiteBackend) {
            return;
        }

        if ($backend->isPrimary()) {
            Log::warning('site-backend teardown: refusing to destroy the primary backend', [
                'backend_id' => $backend->id,
                'site_id' => $backend->site_id,
            ]);

            return;
        }

        // The child Site (the code on the backend box) goes away with the box;
        // delete the row first so nothing dangles if server deletion is async.
        $backend->backendSite?->delete();

        $server = $backend->server;
        if ($server !== null) {
            $actor = $this->actorId !== null ? User::query()->find($this->actorId) : null;
            try {
                $deleteServer->execute($server, $actor, [
                    'reason' => 'site_backend_scale_down',
                    'site_id' => (string) $backend->site_id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('site-backend teardown: server deletion failed', [
                    'backend_id' => $backend->id,
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $backend->delete();
    }
}
