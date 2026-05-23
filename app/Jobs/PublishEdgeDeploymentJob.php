<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Services\Edge\EdgeDeploymentPruner;
use App\Services\Edge\EdgeRouter;
use App\Services\Edge\EdgeTestingHostnameProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Throwable;

class PublishEdgeDeploymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $deploymentId,
        public string $localArtifactDir,
    ) {}

    public function handle(): void
    {
        $deployment = EdgeDeployment::query()->find($this->deploymentId);
        if ($deployment === null) {
            return;
        }

        $site = Site::query()->find($deployment->site_id);
        if ($site === null) {
            return;
        }

        $backend = EdgeRouter::backendFor($site);
        if ($backend === null) {
            $this->markFailed($site, $deployment, 'No edge backend available.');

            return;
        }

        $deployment->update(['status' => EdgeDeployment::STATUS_PUBLISHING]);

        try {
            $result = $backend->publishDeployment($deployment, $site, $this->localArtifactDir);

            EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->update(['status' => EdgeDeployment::STATUS_SUPERSEDED]);

            $deployment->update([
                'status' => EdgeDeployment::STATUS_LIVE,
                'published_at' => now(),
                'cf_kv_version' => $result['cf_kv_version'],
            ]);

            $meta = $site->edgeMeta();
            $meta['live_url'] = $result['live_url'];
            $meta['active_deployment_id'] = $deployment->id;
            $hostname = parse_url((string) ($result['live_url'] ?? ''), PHP_URL_HOST);
            if (is_string($hostname) && $hostname !== '') {
                $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
                $routing['hostname'] = strtolower($hostname);
                $meta['routing'] = $routing;
            }
            unset($meta['last_error'], $meta['last_error_at']);

            $site->update([
                'status' => Site::STATUS_EDGE_ACTIVE,
                'edge_backend_id' => (string) ($site->edge_backend_id ?: $deployment->id),
                'meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta]),
            ]);

            try {
                app(EdgeTestingHostnameProvisioner::class)->provision($site->fresh());
            } catch (Throwable) {
                // DNS is best-effort — KV publish already succeeded.
            }

            try {
                app(EdgeDeploymentPruner::class)->prune($site->fresh());
            } catch (Throwable) {
                // Pruning is best-effort — old artifacts will be retried next publish.
            }
        } catch (Throwable $e) {
            $this->markFailed($site, $deployment, $e->getMessage());

            throw $e;
        } finally {
            if (is_dir($this->localArtifactDir) && str_contains($this->localArtifactDir, sys_get_temp_dir())) {
                File::deleteDirectory(dirname($this->localArtifactDir));
            }
        }
    }

    private function markFailed(Site $site, EdgeDeployment $deployment, string $message): void
    {
        $meta = $site->edgeMeta();
        $meta['last_error'] = $message;
        $meta['last_error_at'] = now()->toIso8601String();
        $site->update([
            'status' => Site::STATUS_EDGE_FAILED,
            'meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta]),
        ]);
        $deployment->update([
            'status' => EdgeDeployment::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $message,
        ]);
    }
}
