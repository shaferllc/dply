<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Services\Edge\EdgeArtifactPublisher;
use App\Services\Edge\EdgeBuildRunner;
use App\Services\Edge\EdgeDeliveryContextResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Throwable;

class BuildEdgeSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public string $deploymentId,
        public ?string $commitOverride = null,
    ) {}

    public function handle(EdgeBuildRunner $runner): void
    {
        $deployment = EdgeDeployment::query()->find($this->deploymentId);
        if ($deployment === null) {
            return;
        }

        $site = Site::query()->find($deployment->site_id);
        if ($site === null) {
            return;
        }

        $edge = $site->edgeMeta();
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];

        $repo = (string) ($source['repo'] ?? '');
        $branch = (string) ($source['branch'] ?? 'main');
        $buildCommand = (string) ($build['command'] ?? 'npm ci && npm run build');
        $outputDir = (string) ($build['output_dir'] ?? 'dist');

        $site->update(['status' => Site::STATUS_EDGE_PROVISIONING]);
        $deployment->update(['status' => EdgeDeployment::STATUS_BUILDING]);

        $buildResult = null;
        $workRoot = null;

        try {
            $repoUrl = str_contains($repo, '://') ? $repo : 'https://github.com/'.$repo.'.git';
            $buildResult = $runner->build($deployment, $repoUrl, $branch, $buildCommand, $outputDir, [], $this->commitOverride);
            $artifactDir = $buildResult['artifact_dir'];
            $workRoot = dirname($artifactDir);

            $buildLogPath = $this->persistBuildLog($site, $deployment, $buildResult['build_log']);
            $updates = ['build_log_path' => $buildLogPath];
            if (is_string($buildResult['git_commit'] ?? null) && $buildResult['git_commit'] !== '') {
                $updates['git_commit'] = $buildResult['git_commit'];
            }
            $deployment->update($updates);

            if (! Site::query()->whereKey($site->id)->exists()) {
                if (is_dir($artifactDir) && str_contains($artifactDir, sys_get_temp_dir())) {
                    File::deleteDirectory($workRoot);
                }

                return;
            }

            PublishEdgeDeploymentJob::dispatch($deployment->id, $artifactDir);
        } catch (Throwable $e) {
            if (is_array($buildResult) && isset($buildResult['build_log']) && is_file($buildResult['build_log'])) {
                try {
                    $buildLogPath = $this->persistBuildLog($site, $deployment, $buildResult['build_log']);
                    $deployment->update(['build_log_path' => $buildLogPath]);
                } catch (Throwable) {
                    // Best-effort — failure reason still captures the exception message.
                }
            }

            $this->markFailed($site, $deployment, $e->getMessage());

            throw $e;
        }
    }

    private function persistBuildLog(Site $site, EdgeDeployment $deployment, string $localLogPath): string
    {
        $storageKey = trim($deployment->storage_prefix, '/').'/build.log';

        try {
            $context = app(EdgeDeliveryContextResolver::class)->forSite($site);
            $diskName = $context->diskName;
        } catch (Throwable) {
            $diskName = (string) config('edge.disk.name', 'edge_r2');
        }

        app(EdgeArtifactPublisher::class)->uploadFile($localLogPath, $storageKey, $diskName);

        return $storageKey;
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
