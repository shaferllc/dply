<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EdgeDeployment;
use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Services\Edge\EdgeArtifactPublisher;
use App\Services\Edge\EdgeBuildRunner;
use App\Services\Edge\EdgeDeliveryContextResolver;
use App\Support\Edge\EdgeRepoRoot;
use App\Support\ProductLine\ProductLineKillSwitches;
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

        $site = Site::find($deployment->site_id);
        if ($site === null) {
            return;
        }

        if (ProductLineKillSwitches::blocksEdgeDelivery()) {
            $deployment->update([
                'status' => EdgeDeployment::STATUS_FAILED,
                'last_error' => 'Edge delivery is paused by platform administrators.',
            ]);
            $site->update(['status' => Site::STATUS_EDGE_FAILED]);

            return;
        }

        $edge = $site->edgeMeta();
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];

        $repo = (string) ($source['repo'] ?? '');
        $branch = (string) ($source['branch'] ?? 'main');
        $buildCommand = (string) ($build['command'] ?? 'npm ci && npm run build');
        $outputDir = (string) ($build['output_dir'] ?? 'dist');
        $runtimeMode = (string) ($edge['runtime_mode'] ?? EdgeBuildRunner::MODE_STATIC);

        $site->update(['status' => Site::STATUS_EDGE_PROVISIONING]);
        $deployment->update(['status' => EdgeDeployment::STATUS_BUILDING]);

        $buildResult = null;
        $workRoot = null;

        try {
            $repoUrl = str_contains($repo, '://') ? $repo : 'https://github.com/'.$repo.'.git';

            // P-env: production-scope vars become Docker -e flags
            // (EdgeBuildRunner::dockerEnvFlags). Values are pulled
            // through the encrypted accessor and filtered against the
            // model's RESERVED_NAMES so customer code can't shadow
            // platform bindings like HOST_MAP / ASSETS / DEPLOYMENT_ID.
            $buildEnv = [];
            foreach ($site->edgeEnvVars()->where('scope', 'production')->get() as $envVar) {
                if (! EdgeSiteEnvVar::keyIsValid($envVar->key)) {
                    continue;
                }
                $buildEnv[$envVar->key] = (string) $envVar->value;
            }

            $buildResult = $runner->build(
                $deployment,
                $repoUrl,
                $branch,
                $buildCommand,
                $outputDir,
                $buildEnv,
                $this->commitOverride,
                $runtimeMode,
                EdgeRepoRoot::normalize(is_string($source['repo_root'] ?? null) ? $source['repo_root'] : null) ?: null,
            );
            $artifactDir = $buildResult['artifact_dir'];
            $workRoot = dirname($artifactDir);

            $buildLogPath = $this->persistBuildLog($site, $deployment, $buildResult['build_log']);
            $updates = ['build_log_path' => $buildLogPath];
            if (is_string($buildResult['git_commit'] ?? null) && $buildResult['git_commit'] !== '') {
                $updates['git_commit'] = $buildResult['git_commit'];
            }
            // Persist commit subject/author into deployment.meta so the
            // previews row + deploy history can show "what is this".
            $commitMeta = array_filter([
                'subject' => $buildResult['git_commit_subject'] ?? null,
                'author' => $buildResult['git_commit_author'] ?? null,
                'committed_at' => $buildResult['git_commit_at'] ?? null,
            ], fn ($value) => is_string($value) && $value !== '');
            if ($commitMeta !== []) {
                $existingMeta = $deployment->meta;
                $updates['meta'] = array_merge($existingMeta, ['commit' => $commitMeta]);
            }
            $deployment->update($updates);

            if (! Site::query()->whereKey($site->id)->exists()) {
                if (is_dir($artifactDir) && str_contains($artifactDir, sys_get_temp_dir())) {
                    File::deleteDirectory($workRoot);
                }

                return;
            }

            // SSR: persist the bundled worker module(s) into a sidecar
            // file next to the artifact dir so PublishEdgeDeploymentJob
            // (which re-resolves over a queue boundary) can find it
            // without us shoving it through job args / the DB.
            $ssrSidecarPath = null;
            if (is_array($buildResult['ssr_modules'] ?? null) && $buildResult['ssr_modules'] !== []) {
                $ssrSidecarPath = $workRoot.'/ssr-bundle.json';
                File::put($ssrSidecarPath, json_encode([
                    'entry_module' => $buildResult['ssr_entry_module'] ?? 'worker.js',
                    'modules' => $buildResult['ssr_modules'],
                ], JSON_THROW_ON_ERROR));
            }

            // Middleware: same sidecar pattern, separate file so a
            // middleware-only deploy doesn't get conflated with SSR
            // and so the two can coexist on a single deployment.
            $middlewareSidecarPath = null;
            if (is_array($buildResult['middleware_modules'] ?? null) && $buildResult['middleware_modules'] !== []) {
                $middlewareSidecarPath = $workRoot.'/middleware-bundle.json';
                File::put($middlewareSidecarPath, json_encode([
                    'entry_module' => $buildResult['middleware_entry_module'] ?? 'middleware.js',
                    'source_path' => $buildResult['middleware_source_path'] ?? null,
                    'modules' => $buildResult['middleware_modules'],
                ], JSON_THROW_ON_ERROR));
            }

            PublishEdgeDeploymentJob::dispatch(
                $deployment->id,
                $artifactDir,
                $ssrSidecarPath,
                $middlewareSidecarPath,
            );
        } catch (Throwable $e) {
            if (is_array($buildResult) && is_file($buildResult['build_log'])) {
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
            'meta' => array_merge($site->meta, ['edge' => $meta]),
        ]);
        $deployment->update([
            'status' => EdgeDeployment::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $message,
        ]);
    }
}
