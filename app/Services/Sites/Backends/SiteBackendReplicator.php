<?php

declare(strict_types=1);

namespace App\Services\Sites\Backends;

use App\Jobs\ProvisionSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SiteDeployment;
use App\Models\SiteProcess;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\WorkerPools\WorkerWorkloadReplayer;
use Illuminate\Support\Str;

/**
 * Materialises the logical site's code onto a backend server as a derived child
 * Site (linked via `parent_site_id`), then provisions it. The WEB counterpart to
 * {@see WorkerWorkloadReplayer} — same field-copy, but
 * NONE of the worker transforms: the scheduler flag is preserved, there's no
 * DPLY_WORKER_ROLE demotion, and scheduler processes are kept (a backend serves
 * HTTP exactly like the primary; the balancer fans traffic across them).
 *
 * Env is copied verbatim (same-region/same-network v1) so private service IPs
 * resolve. See docs/MULTI_BACKEND_SITES.md.
 */
class SiteBackendReplicator
{
    public function __construct(
        private readonly SiteDeployPipelineManager $pipelines,
    ) {}

    /**
     * Create the backend's child Site on $target (a provisioned backend server),
     * provision it, and return the new Site. Pins to the source's last successful
     * release so a fresh backend never runs ahead of the primary.
     */
    public function replicate(Site $source, Server $target): Site
    {
        $source->loadMissing(['processes', 'bindings']);

        $pinnedSha = (string) ($source->deployments()
            ->where('status', SiteDeployment::STATUS_SUCCESS)
            ->reorder('started_at', 'desc')
            ->value('git_sha') ?? '');
        $gitBranch = $pinnedSha !== '' ? $pinnedSha : $source->git_branch;

        $child = Site::query()->create([
            'server_id' => $target->id,
            'parent_site_id' => $source->id,
            'user_id' => $target->user_id,
            'organization_id' => $target->organization_id,
            'name' => $source->name,
            'slug' => $this->uniqueSlug($target, $source->slug ?: 'backend'),
            'type' => $source->type,
            'runtime' => $source->runtime,
            'runtime_version' => $source->runtime_version,
            'database_engine' => $source->database_engine,
            'document_root' => $source->document_root,
            'repository_path' => $source->repository_path,
            'build_command' => $source->build_command,
            'start_command' => $source->start_command,
            'app_port' => $source->app_port,
            'internal_port' => $source->internal_port,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'git_repository_url' => $source->git_repository_url,
            'git_branch' => $gitBranch,
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => $source->deploy_strategy ?: 'simple',
            'deploy_method' => $source->deploy_method,
            'releases_to_keep' => $source->releases_to_keep ?: 5,
            // WEB backend: keep the scheduler flag as the source has it. (Whether
            // every backend should run the scheduler is a separate concern handled
            // at the rollout layer; we don't silently disable it the way a worker
            // replica does.)
            'laravel_scheduler' => (bool) $source->laravel_scheduler,
            'deployment_environment' => $source->deployment_environment ?: 'production',
            'restart_supervisor_programs_after_deploy' => (bool) $source->restart_supervisor_programs_after_deploy,
            'env_file_content' => (string) $source->env_file_content,
            'meta' => $this->childSiteMeta($source, $pinnedSha),
        ]);

        $this->replicateProcesses($source, $child);
        $this->replicateBindings($source, $child);

        $framework = (string) ($source->meta['framework'] ?? '');
        $this->pipelines->seedRuntimeDefaults(
            $child,
            $child->runtime,
            $framework !== '' ? $framework : null,
        );

        // Provision (webserver + reachability). The first deploy is owned by the
        // reconciler, fired once this site reports provisioning complete.
        ProvisionSiteJob::dispatch($child->id);

        return $child;
    }

    private function replicateProcesses(Site $source, Site $child): void
    {
        foreach ($source->processes as $process) {
            // 'web' is auto-created by Site's booted hook; don't duplicate it.
            if ($process->type === SiteProcess::TYPE_WEB) {
                continue;
            }

            $child->processes()->create([
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

    private function replicateBindings(Site $source, Site $child): void
    {
        foreach ($source->bindings as $binding) {
            SiteBinding::query()->create([
                'site_id' => $child->id,
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
     * @return array<string, mixed>
     */
    private function childSiteMeta(Site $source, string $pinnedSha): array
    {
        $meta = ($source->meta );

        // Drop per-site provisioning/runtime state so the child starts clean,
        // but keep operator intent (repository config, git ref kind, …).
        foreach (['provisioning', 'testing_hostname', 'caddy_last_output', 'ssl_last_output', 'health', 'env_requirements', 'backend_group'] as $drop) {
            unset($meta[$drop]);
        }

        $meta['site_backend_of'] = (string) $source->id;

        if ($pinnedSha !== '') {
            $meta['git_ref_kind'] = 'commit';
            $meta['pinned_release'] = $pinnedSha;
        }

        return $meta;
    }

    private function uniqueSlug(Server $target, string $base): string
    {
        $base = Str::slug($base) ?: 'backend';
        $slug = $base;
        $i = 2;
        while (Site::query()->where('server_id', $target->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
