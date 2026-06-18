<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Enums\ServerProvider;
use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteProcess;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\Sites\InternalPortAllocator;
use App\Services\Sites\SiteFoundationProvisioner;
use App\Services\Sites\SiteProvisioner;
use App\Support\HostnameValidator;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteCreateStore
{
    public function store(SiteProvisioner $siteProvisioner): mixed
    {
        $this->authorize('update', $this->server);

        $org = auth()->user()->currentOrganization();
        abort_if($org === null, 403);
        abort_if($this->server->organization_id === null, 403);
        abort_if($this->server->organization_id !== $org->id, 403);

        // Friendly site-quota check first so the user gets an upgrade toast
        // rather than a raw 403 from the create policy (which also gates on
        // the plan site ceiling as the authoritative hard block).
        if ($this->siteQuotaReached($org)) {
            return null;
        }

        $this->authorize('create', Site::class);

        $phpVersionIds = array_column($this->phpVersions, 'id');
        $functionsHost = $this->server->hostCapabilities()->supportsFunctionDeploy();
        $dockerHost = $this->server->isDockerHost();
        $kubernetesHost = $this->server->isKubernetesCluster();
        $containerHost = $dockerHost || $kubernetesHost;
        // Headless host (webserver=none): no domain, no web root — the site
        // just runs deployed code via the standard pipeline + processes.
        $headlessHost = (($this->server->meta['webserver'] ?? 'nginx') === 'none')
            && ! $functionsHost && ! $containerHost;

        $rules = [
            'name' => 'required|string|max:120',
            'type' => 'required|in:php,static,node',
            'document_root' => $headlessHost ? 'nullable|string|max:500' : 'required|string|max:500',
            'repository_path' => 'nullable|string|max:500',
            'php_version' => 'nullable|string|max:10',
            'app_port' => 'nullable|integer|min:1|max:65535',
            'functions_runtime' => 'nullable|string|max:50',
            'functions_entrypoint' => 'nullable|string|max:255',
            'functions_repo_source' => 'nullable|string|in:manual,provider',
            'functions_source_control_account_id' => 'nullable|string|max:26',
            'functions_repository_selection' => 'nullable|string|max:500',
            'functions_repository_url' => 'nullable|string|max:500',
            'functions_repository_branch' => 'nullable|string|max:120',
            'functions_repository_subdirectory' => 'nullable|string|max:255',
            'functions_build_command' => 'nullable|string|max:4000',
            'functions_artifact_output_path' => 'nullable|string|max:255',
            'primary_hostname' => [
                'nullable',
                'string',
                'max:255',
                'unique:site_domains,hostname',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }
                    if (! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid domain name like app.example.com.');
                    }
                },
            ],
        ];

        if ($this->form->type === 'php' && ! $functionsHost && ! $containerHost && ! $this->usesVmDockerDeployStack()) {
            $rules['php_version'] = ['required', 'string', 'max:10'];

            if ($phpVersionIds !== []) {
                $rules['php_version'][] = 'in:'.implode(',', $phpVersionIds);
            }
        }

        if ($functionsHost) {
            if (($this->functionsDetection['unsupported_for_target'] ?? false) === true) {
                $this->addError('form.functions_repository_url', (string) (($this->functionsDetection['warnings'][0] ?? __('This repository runtime is not supported by the selected target.'))));

                return null;
            }

            $rules['functions_runtime'] = ['required', 'string', 'max:50'];
            $rules['functions_entrypoint'] = ['required', 'string', 'max:255'];
            $rules['functions_repo_source'] = ['required', 'string', 'in:manual,provider'];
            $rules['functions_repository_url'] = ['required', 'string', 'max:500'];
            $rules['functions_repository_branch'] = ['required', 'string', 'max:120'];
            $rules['functions_build_command'] = ['required', 'string', 'max:4000'];
            $rules['functions_artifact_output_path'] = ['required', 'string', 'max:255'];

            if ($this->form->functions_repo_source === 'provider') {
                $rules['functions_source_control_account_id'] = ['required', 'string', 'max:26'];
            }
        }

        $this->form->validate($rules, [
            'php_version.required' => __('Choose a PHP version for this site.'),
            'php_version.in' => __('Choose a PHP version that is currently installed on this server.'),
        ]);

        $org = $this->server->organization;

        $meta = [];
        if ($functionsHost) {
            $detectedRuntime = is_array($this->functionsDetection) ? $this->functionsDetection : [];
            $meta['runtime_profile'] = $this->server->isAwsLambdaHost() ? 'aws_lambda_bref_web' : 'digitalocean_functions_web';
            $meta['serverless'] = [
                'target' => $this->server->hostKind(),
                'runtime' => $this->form->functions_runtime,
                'entrypoint' => trim($this->form->functions_entrypoint),
                'package' => trim((string) ($detectedRuntime['package'] ?? '')),
                'function_name' => Str::slug($this->form->name) ?: 'site',
                'repo_source' => trim($this->form->functions_repo_source),
                'source_control_account_id' => $this->form->functions_repo_source === 'provider'
                    ? trim($this->form->functions_source_control_account_id)
                    : null,
                'repository_subdirectory' => trim($this->form->functions_repository_subdirectory),
                'build_command' => trim($this->form->functions_build_command),
                'artifact_output_path' => trim($this->form->functions_artifact_output_path),
                'detected_runtime' => $detectedRuntime !== [] ? $detectedRuntime : null,
            ];
        } elseif ($dockerHost) {
            $meta['runtime_profile'] = 'docker_web';
            $meta['runtime_target'] = [
                'family' => match ($this->server->provider) {
                    ServerProvider::DigitalOcean => 'digitalocean_docker',
                    ServerProvider::Aws => 'aws_docker',
                    default => data_get($this->server->meta, 'local_runtime.provider') === 'orbstack'
                        ? 'local_orbstack_docker'
                        : 'docker',
                },
                'platform' => data_get($this->server->meta, 'local_runtime.provider') === 'orbstack'
                    ? 'local'
                    : match ($this->server->provider) {
                        ServerProvider::DigitalOcean => 'digitalocean',
                        ServerProvider::Aws => 'aws',
                        default => 'byo',
                    },
                'provider' => data_get($this->server->meta, 'local_runtime.provider') === 'orbstack'
                    ? 'orbstack'
                    : ($this->server->provider?->value ?? 'byo'),
                'mode' => 'docker',
                'status' => 'pending',
                'logs' => [],
            ];
            $meta['docker_runtime'] = [
                'app_type' => $this->form->type,
            ];
        } elseif ($this->usesVmDockerDeployStack()) {
            $meta['runtime_profile'] = 'docker_web';
            $meta['runtime_target'] = [
                'family' => 'byo_vm_docker',
                'platform' => 'byo',
                'provider' => $this->server->provider?->value ?? 'byo',
                'mode' => 'docker',
                'status' => 'pending',
                'logs' => [],
                'vm_docker' => true,
            ];
            $meta['docker_runtime'] = [
                'app_type' => $this->form->type,
            ];
        } elseif ($kubernetesHost) {
            $meta['runtime_profile'] = 'kubernetes_web';
            $meta['runtime_target'] = [
                'family' => match ($this->server->provider) {
                    ServerProvider::DigitalOcean => 'digitalocean_kubernetes',
                    ServerProvider::Aws => 'aws_kubernetes',
                    default => data_get($this->server->meta, 'local_runtime.provider') === 'orbstack'
                        ? 'local_orbstack_kubernetes'
                        : 'kubernetes',
                },
                'platform' => data_get($this->server->meta, 'local_runtime.provider') === 'orbstack'
                    ? 'local'
                    : match ($this->server->provider) {
                        ServerProvider::DigitalOcean => 'digitalocean',
                        ServerProvider::Aws => 'aws',
                        default => 'byo',
                    },
                'provider' => data_get($this->server->meta, 'local_runtime.provider') === 'orbstack'
                    ? 'orbstack'
                    : ($this->server->provider?->value ?? 'byo'),
                'mode' => 'kubernetes',
                'status' => 'pending',
                'logs' => [],
            ];
            $meta['kubernetes_runtime'] = [
                'app_type' => $this->form->type,
                'namespace' => (string) data_get($this->server->meta, 'kubernetes.namespace', 'default'),
            ];
        }

        // The new runtime-agnostic fields drive the URL-first flow. When
        // they aren't populated (legacy flow, or non-VM hosts) we fall
        // back to the existing type-based logic so behavior is unchanged.
        $effectiveRuntime = $this->form->runtime !== ''
            ? $this->form->runtime
            : $this->form->type;
        $allocatesInternalPort = ! $functionsHost
            && ! $containerHost
            && ! $this->usesVmDockerDeployStack()
            && ! in_array($effectiveRuntime, ['php', 'static'], true);
        $internalPort = null;
        if ($allocatesInternalPort) {
            $internalPort = app(InternalPortAllocator::class)->allocate($this->server->id);
            if ($internalPort === null) {
                $this->addError(
                    'form.runtime',
                    __('No free internal port available on this server (range 30000–39999 is full).'),
                );

                return null;
            }
        }

        $vmGitUrl = trim($this->form->git_repository_url);
        $vmGitBranch = trim($this->form->git_branch) !== '' ? trim($this->form->git_branch) : 'main';

        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'deploy_script_id' => $org?->default_site_script_id,
            'name' => $this->form->name,
            'slug' => Str::slug($this->form->name) ?: 'site',
            'type' => SiteType::from($this->form->type),
            // Prefer the explicit `runtime` set by detection or wizard;
            // when the form only provides the legacy `type`, derive an
            // equivalent runtime key so the new schema is always populated.
            'runtime' => $this->form->runtime !== '' ? $this->form->runtime : $this->form->type,
            // Prefer the new runtime_version field; for legacy PHP-only flow
            // (no detection), copy form->php_version into runtime_version.
            'runtime_version' => $this->form->runtime_version !== ''
                ? $this->form->runtime_version
                : ($this->form->type === 'php' && ! $functionsHost && ! $containerHost && $this->form->php_version !== ''
                    ? $this->form->php_version
                    : null),
            'build_command' => $this->form->build_command !== '' ? $this->form->build_command : null,
            'start_command' => $this->form->start_command !== '' ? $this->form->start_command : null,
            'internal_port' => $internalPort,
            // Persist the engine override only when the user picked one
            // that differs from the server's default; otherwise leave the
            // column null so the Site::databaseEngine() accessor falls
            // back to the server's default. Keeps "follow the server's
            // default" implicit and lets re-default-ing the server
            // automatically apply to sites that haven't pinned.
            'database_engine' => $this->resolveDatabaseEngineOverride(),
            'document_root' => $functionsHost
                ? ($this->server->isAwsLambdaHost()
                    ? '/lambda/'.trim($this->form->functions_entrypoint, '/')
                    : '/functions/'.$this->form->functions_entrypoint)
                : $this->form->document_root,
            'repository_path' => $functionsHost ? null : ($this->form->repository_path ?: null),
            'app_port' => $this->form->type === 'node' ? $this->form->app_port : null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'git_repository_url' => $functionsHost
                ? trim($this->form->functions_repository_url)
                : ($vmGitUrl !== '' ? $vmGitUrl : null),
            'git_branch' => $functionsHost ? trim($this->form->functions_repository_branch) : $vmGitBranch,
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => 'simple',
            'releases_to_keep' => 5,
            'laravel_scheduler' => false,
            'deployment_environment' => 'production',
            'restart_supervisor_programs_after_deploy' => false,
            'meta' => $meta,
        ]);

        $site->ensureUniqueSlug();
        $site->save();

        // The Site::created hook auto-creates a `web` SiteProcess with
        // command=null. For non-PHP runtimes with a detected start command,
        // backfill that command now so the process row is immediately
        // useful.
        if ($this->form->start_command !== '') {
            $site->processes()
                ->where('type', SiteProcess::TYPE_WEB)
                ->update(['command' => $this->form->start_command]);
        }

        // Materialize detector-suggested non-web processes (workers,
        // schedulers) alongside the auto-created web row.
        foreach ($this->detectedProcesses as $detected) {
            $site->processes()->create([
                'type' => $detected['type'],
                'name' => $detected['name'],
                'command' => $detected['command'],
                'scale' => 1,
                'is_active' => true,
            ]);
        }

        // Materialize the canonical default deploy step set for this
        // runtime + framework so the user's first deploy has sensible
        // build/release steps without requiring a trip to the deploy-
        // pipeline editor. Skips when no defaults apply (custom / null
        // runtime / unknown runtime).
        $effectiveFramework = (string) ($this->detectedPlan['framework'] ?? '');
        app(SiteDeployPipelineManager::class)->seedRuntimeDefaults(
            $site,
            $site->runtime,
            $effectiveFramework !== '' ? $effectiveFramework : null,
        );

        // Primary domain is optional at create time — Dply provisions a testing
        // hostname automatically. Only create the SiteDomain row when the user
        // supplied a real customer-facing hostname.
        if (trim((string) $this->form->primary_hostname) !== '') {
            SiteDomain::query()->create([
                'site_id' => $site->id,
                'hostname' => strtolower(trim($this->form->primary_hostname)),
                'is_primary' => true,
                'www_redirect' => false,
            ]);
        }

        $site->loadMissing(['server', 'domains']);
        $siteProvisioner->markQueued($site);
        ProvisionSiteJob::dispatch($site->id);

        if ($this->server->organization) {
            audit_log(
                $this->server->organization,
                auth()->user(),
                'site.created',
                $site,
                null,
                [
                    'name' => $site->name,
                    'slug' => $site->slug,
                    'server_id' => (string) $this->server->id,
                    'type' => (string) $site->type->value,
                    'runtime' => $site->runtime,
                    'runtime_version' => $site->runtime_version,
                    'primary_hostname' => strtolower(trim($this->form->primary_hostname)),
                    'git_repository_url' => $site->git_repository_url,
                    'git_branch' => $site->git_branch,
                ],
            );
        }

        return $this->redirect(route('sites.show', [$this->server, $site]), navigate: true);
    }

    /**
     * Bare-create submit for the choose-app flow (config/dply.php
     * `choose_app_enabled`). Collects only name + primary hostname, creates
     * the Site in STATUS_AWAITING_APP, then redirects to sites.choose-app
     * where the user picks what application runs on it.
     *
     * VM hosts only — the blade only renders the bare form when the flag is
     * on and the host is a VM, but we re-assert both here so the action is
     * never reachable on a non-VM host or with the flag off.
     */
    public function storeBare(): mixed
    {
        $this->authorize('update', $this->server);

        if (! config('dply.choose_app_enabled') || ! $this->server->isVmHost()) {
            abort(404);
        }

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);
        abort_if($this->server->organization_id === null, 403);
        abort_if($this->server->organization_id !== $org->id, 403);

        if ($this->siteQuotaReached($org)) {
            return null;
        }

        $this->authorize('create', Site::class);

        $this->form->validate([
            'name' => ['required', 'string', 'max:120'],
            'primary_hostname' => [
                'nullable',
                'string',
                'max:255',
                'unique:site_domains,hostname',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }
                    if (! HostnameValidator::isValid($value)) {
                        $fail(__('Enter a valid domain name like app.example.com.'));
                    }
                },
            ],
        ]);

        // Resolve slug uniqueness BEFORE the insert. ensureUniqueSlug() only
        // dedupes pre-save; passing a colliding slug straight to create()
        // trips the (server_id, slug) unique constraint. Mirror the loop
        // used by WorkspaceSites::addSite().
        $baseSlug = Str::slug($this->form->name) ?: 'site';
        $slug = $baseSlug;
        $i = 1;
        while (Site::query()->where('server_id', $this->server->id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }
        $hostname = strtolower(trim($this->form->primary_hostname));

        // Bare site: type defaults to PHP as a harmless placeholder; the
        // chosen application on sites.choose-app overwrites type / runtime /
        // document_root before anything is provisioned. No webserver / FPM
        // is provisioned at this point.
        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'deploy_script_id' => $this->server->organization?->default_site_script_id,
            'name' => $this->form->name,
            'slug' => $slug,
            'type' => SiteType::Php,
            'runtime' => 'php',
            'document_root' => '/home/dply/'.$slug.'/public',
            'repository_path' => '/home/dply/'.$slug,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => 'simple',
            'releases_to_keep' => 5,
            'laravel_scheduler' => false,
            'deployment_environment' => 'production',
            'restart_supervisor_programs_after_deploy' => false,
            'meta' => [
                'choose_app' => [
                    'created_at' => now()->toISOString(),
                    'created_by_user_id' => auth()->id(),
                    // Services-first: foundation provisions now; the repo is
                    // connected later. `skipped` keeps it out of the forced
                    // picker while leaving "Connect repository" re-choosable.
                    'skipped' => true,
                ],
            ],
        ]);

        if ($hostname !== '') {
            SiteDomain::query()->create([
                'site_id' => $site->id,
                'hostname' => $hostname,
                'is_primary' => true,
                'www_redirect' => false,
            ]);
        }

        if ($this->server->organization) {
            audit_log(
                $this->server->organization,
                auth()->user(),
                'site.created',
                $site,
                null,
                [
                    'name' => $site->name,
                    'slug' => $site->slug,
                    'server_id' => (string) $this->server->id,
                    'mode' => 'choose_app',
                    'primary_hostname' => $hostname,
                ],
            );
        }

        // Provision the foundation now (live PHP default-page site) so services
        // can be configured against a real site before any repo is connected,
        // then land in the workspace — not the forced app picker.
        app(SiteFoundationProvisioner::class)->provision($site, 'php');

        return $this->redirect(route('sites.show', [
            'server' => $this->server,
            'site' => $site,
        ]), navigate: true);
    }
}
