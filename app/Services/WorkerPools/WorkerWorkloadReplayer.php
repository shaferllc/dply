<?php

namespace App\Services\WorkerPools;

use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SiteDeployment;
use App\Models\SiteProcess;
use App\Models\WorkerPool;
use App\Services\Deploy\SiteDeployPipelineManager;
use Illuminate\Support\Str;

/**
 * Copies every Site on a source worker onto a freshly-provisioned pool member
 * (its processes, bindings, and .env), applies the replica transform, then
 * provisions + deploys each replicated site so the new box starts draining the
 * same queue.
 *
 * Same-region only (v1): env is copied verbatim because the clone shares the
 * source's private network, so private service IPs resolve. Cross-region host
 * rewriting is Phase 2 (see the spec).
 */
class WorkerWorkloadReplayer
{
    public function __construct(
        private readonly SiteDeployPipelineManager $pipelines,
    ) {}

    /**
     * Replicate all of $source's sites onto $target. $target should already be
     * provisioned and ready. When $asReplica is true the replica transform is
     * applied (no scheduler, DPLY_WORKER_ROLE rewritten).
     *
     * @return int number of sites replicated
     */
    public function replicate(Server $source, Server $target, bool $asReplica = true): int
    {
        $source->loadMissing(['sites.processes', 'sites.bindings']);
        $count = 0;

        foreach ($source->sites as $sourceSite) {
            $this->replicateSite($sourceSite, $target, $asReplica);
            $count++;
        }

        return $count;
    }

    private function replicateSite(Site $sourceSite, Server $target, bool $asReplica): void
    {
        $newSite = Site::query()->create([
            'server_id' => $target->id,
            'user_id' => $target->user_id,
            'organization_id' => $target->organization_id,
            'name' => $sourceSite->name,
            'slug' => $this->uniqueSlug($target, $sourceSite->slug ?: 'worker'),
            'type' => $sourceSite->type,
            'runtime' => $sourceSite->runtime,
            'runtime_version' => $sourceSite->runtime_version,
            'database_engine' => $sourceSite->database_engine,
            'document_root' => $sourceSite->document_root,
            'repository_path' => $sourceSite->repository_path,
            'build_command' => $sourceSite->build_command,
            'start_command' => $sourceSite->start_command,
            'app_port' => $sourceSite->app_port,
            'internal_port' => $sourceSite->internal_port,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'git_repository_url' => $sourceSite->git_repository_url,
            'git_branch' => $sourceSite->git_branch,
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => $sourceSite->deploy_strategy ?: 'simple',
            'releases_to_keep' => $sourceSite->releases_to_keep ?: 5,
            // Replicas never own the scheduler — only the primary does.
            'laravel_scheduler' => $asReplica ? false : (bool) $sourceSite->laravel_scheduler,
            'deployment_environment' => $sourceSite->deployment_environment ?: 'production',
            'restart_supervisor_programs_after_deploy' => (bool) $sourceSite->restart_supervisor_programs_after_deploy,
            'env_file_content' => $this->transformEnv((string) $sourceSite->env_file_content, $asReplica),
            'meta' => $this->replicaSiteMeta($sourceSite),
        ]);

        $this->replicateProcesses($sourceSite, $newSite, $asReplica);
        $this->replicateBindings($sourceSite, $newSite);

        // Seed the deploy pipeline from runtime defaults (mirrors normal create).
        $framework = (string) ($sourceSite->meta['framework'] ?? '');
        $this->pipelines->seedRuntimeDefaults(
            $newSite,
            $newSite->runtime,
            $framework !== '' ? $framework : null,
        );

        // Provision the site (caddy + worker page for a worker host), then deploy
        // the code so the worker units come up. The deploy is delayed to give
        // provisioning a head start; the deploy job re-reads the site fresh.
        // NOTE: this ordering is best-effort in v1 — see spec "Open Questions".
        ProvisionSiteJob::dispatch($newSite->id);
        RunSiteDeploymentJob::dispatch($newSite->fresh(), SiteDeployment::TRIGGER_MANUAL)
            ->delay(now()->addSeconds(45));
    }

    private function replicateProcesses(Site $sourceSite, Site $newSite, bool $asReplica): void
    {
        foreach ($sourceSite->processes as $process) {
            // 'web' is auto-created by Site's booted hook; don't duplicate it.
            if ($process->type === SiteProcess::TYPE_WEB) {
                continue;
            }
            // Replicas run workers only — the primary keeps the scheduler.
            if ($asReplica && $process->type === SiteProcess::TYPE_SCHEDULER) {
                continue;
            }

            $newSite->processes()->create([
                'type' => $process->type,
                'name' => $process->name,
                'command' => $process->command,
                'scale' => $process->scale,
                'env_vars' => $process->env_vars,
                'working_directory' => $process->working_directory,
                'user' => $process->user,
                'is_active' => $process->is_active,
            ]);
        }
    }

    private function replicateBindings(Site $sourceSite, Site $newSite): void
    {
        foreach ($sourceSite->bindings as $binding) {
            SiteBinding::query()->create([
                'site_id' => $newSite->id,
                'type' => $binding->type,
                'mode' => $binding->mode,
                'status' => $binding->status,
                'name' => $binding->name,
                'target_type' => $binding->target_type,
                'target_id' => $binding->target_id,
                'injected_env' => $binding->injected_env,
                'config' => $binding->config,
            ]);
        }
    }

    /**
     * Replica transform on the .env: demote a primary worker role so the clone
     * doesn't double-run the scheduler / primary-only work.
     */
    private function transformEnv(string $env, bool $asReplica): string
    {
        if (! $asReplica || $env === '') {
            return $env;
        }

        return preg_replace(
            '/^(\s*DPLY_WORKER_ROLE\s*=\s*)primary\s*$/mi',
            '${1}replica',
            $env
        ) ?? $env;
    }

    /**
     * @return array<string, mixed>
     */
    private function replicaSiteMeta(Site $sourceSite): array
    {
        $meta = is_array($sourceSite->meta) ? $sourceSite->meta : [];

        // Drop per-site provisioning/runtime state so the clone starts clean,
        // but keep operator intent (repository config, git ref kind, choose_app).
        foreach (['provisioning', 'testing_hostname', 'caddy_last_output', 'ssl_last_output', 'health', 'env_requirements'] as $drop) {
            unset($meta[$drop]);
        }

        $meta['replicated_from_site_id'] = (string) $sourceSite->id;

        return $meta;
    }

    private function uniqueSlug(Server $target, string $base): string
    {
        $base = Str::slug($base) ?: 'worker';
        $slug = $base;
        $i = 2;
        while (Site::query()->where('server_id', $target->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
