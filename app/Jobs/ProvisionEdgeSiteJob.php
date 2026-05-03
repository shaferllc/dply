<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Edge\EdgeRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Calls the edge backend to provision a new container app for
 * the given Site, and persists the returned backend identifier
 * (DO app id, App Runner ARN) + live URL onto the Site row.
 */
class ProvisionEdgeSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $backend = EdgeRouter::backendFor($site);
        $credential = EdgeRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            $this->markFailed($site, 'No backend or credential available for site.');

            return;
        }

        $site->update(['status' => Site::STATUS_CONTAINER_PROVISIONING]);

        try {
            $result = $backend->provision($site, $credential);
        } catch (Throwable $e) {
            $this->markFailed($site, $e->getMessage());

            throw $e;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['container'] = array_merge($meta['container'] ?? [], [
            'live_url' => $result['live_url'],
            'backend' => $backend->providerKey(),
            'credential_id' => $credential->id,
            'provisioned_at' => now()->toIso8601String(),
        ]);

        $site->update([
            'container_backend_id' => $result['backend_id'],
            'status' => $result['live_url'] !== null
                ? Site::STATUS_CONTAINER_ACTIVE
                : Site::STATUS_CONTAINER_PROVISIONING,
            'meta' => $meta,
        ]);
    }

    private function markFailed(Site $site, string $message): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['container'] = array_merge($meta['container'] ?? [], [
            'last_error' => $message,
            'last_error_at' => now()->toIso8601String(),
        ]);
        $site->update([
            'status' => Site::STATUS_CONTAINER_FAILED,
            'meta' => $meta,
        ]);
    }
}
