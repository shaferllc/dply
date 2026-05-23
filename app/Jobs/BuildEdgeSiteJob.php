<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Services\Edge\EdgeBuildRunner;
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

    public function __construct(public string $deploymentId) {}

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

        try {
            $repoUrl = str_contains($repo, '://') ? $repo : 'https://github.com/'.$repo.'.git';
            $artifactDir = $runner->build($deployment, $repoUrl, $branch, $buildCommand, $outputDir);

            if (! Site::query()->whereKey($site->id)->exists()) {
                if (is_dir($artifactDir) && str_contains($artifactDir, sys_get_temp_dir())) {
                    File::deleteDirectory(dirname($artifactDir));
                }

                return;
            }

            PublishEdgeDeploymentJob::dispatch($deployment->id, $artifactDir);
        } catch (Throwable $e) {
            $this->markFailed($site, $deployment, $e->getMessage());

            throw $e;
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
