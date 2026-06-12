<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\FinalizeContainerCloudLaunchJob;
use App\Jobs\ProvisionSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\LocalRepositoryInspector;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteCreateContainer
{
    /**
     * True when the target server is a container-shaped host (docker or
     * kubernetes). Drives the create page's branch between the existing
     * VM-shaped form and the new container-mode form.
     */
    public function isContainerMode(): bool
    {
        return in_array(
            $this->server->hostKind(),
            [Server::HOST_KIND_DOCKER, Server::HOST_KIND_KUBERNETES],
            true,
        );
    }

    /**
     * Docker container deploy on a regular VM (compose + host port + webserver proxy).
     */
    public function usesVmDockerDeployStack(): bool
    {
        return $this->form->deploy_stack === 'docker'
            && ! $this->isContainerMode()
            && $this->server->dockerEnginePresent();
    }

    /**
     * True when the URL requests VM Docker deploy (`?deploy_stack=docker`) on a
     * regular VM host, even if Docker has not been probed yet.
     */
    public function requestsVmDockerDeployStack(): bool
    {
        if ($this->isContainerMode()
            || $this->server->isDockerHost()
            || $this->server->isKubernetesCluster()) {
            return false;
        }

        $deployStack = request()->query('deploy_stack');

        return is_string($deployStack) && $deployStack === 'docker';
    }

    /**
     * Choose-app bare create (name + domain only) — skipped for VM Docker
     * deep links so the full deploy-target wizard stays reachable.
     */
    public function usesChooseAppBareCreate(): bool
    {
        if ($this->siteCreateBlockedReason !== '') {
            return false;
        }

        return config('dply.choose_app_enabled')
            && $this->server->isVmHost()
            && ! $this->isContainerMode()
            && ! $this->usesVmDockerDeployStack()
            && ! $this->requestsVmDockerDeployStack();
    }

    public function dockerDeployRequestedButMissing(): bool
    {
        return $this->requestsVmDockerDeployStack()
            && ! $this->server->dockerEnginePresent();
    }

    private function initializeContainerMode(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        $this->containerLinkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());
        if ($this->containerLinkedSourceControlAccounts !== []) {
            $this->container_source_control_account_id = (string) $this->containerLinkedSourceControlAccounts[0]['id'];
            $this->refreshContainerRepositories($repositoryBrowser);
        }

        // K8s container apps land in the server's default namespace unless the
        // user overrides per-container at create time (per Q10-C).
        if ($this->server->hostKind() === Server::HOST_KIND_KUBERNETES && $this->container_kubernetes_namespace === '') {
            $defaultNamespace = (string) data_get($this->server->meta, 'kubernetes.namespace', 'default');
            $this->container_kubernetes_namespace = $defaultNamespace !== '' ? $defaultNamespace : 'default';
        }
    }

    public function updatedContainerSourceControlAccountId(string $value): void
    {
        $this->container_source_control_account_id = $value;
        $this->container_repository_selection = '';
        $this->refreshContainerRepositories(app(SourceControlRepositoryBrowser::class));
    }

    private function refreshContainerRepositories(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        if ($this->container_source_control_account_id === '') {
            $this->containerAvailableRepositories = [];

            return;
        }
        $account = auth()->user() !== null
            ? app(GitIdentityResolver::class)->forId(auth()->user(), $this->container_source_control_account_id)
            : null;
        $this->containerAvailableRepositories = $account
            ? $repositoryBrowser->repositoriesForAccount($account)
            : [];

        if ($this->containerAvailableRepositories !== [] && $this->container_repository_selection === '') {
            $first = $this->containerAvailableRepositories[0];
            $this->container_repository_selection = (string) $first['url'];
            $this->container_repository_branch = (string) ($first['branch'] ?? 'main');
        }
    }

    /**
     * One-click fill from an OSS preset (Plausible, Uptime Kuma, Listmonk,
     * Vaultwarden). Lifted from the deprecated launcher so the container-mode
     * page keeps the same try-an-app affordance.
     *
     * @return list<array{id: string, label: string, description: string, url: string, branch: string, subdirectory: string}>
     */
    public function containerOssPresets(): array
    {
        return [
            ['id' => 'plausible', 'label' => 'Plausible Analytics', 'description' => __('Privacy-friendly web analytics (Elixir).'), 'url' => 'https://github.com/plausible/analytics.git', 'branch' => 'master', 'subdirectory' => ''],
            ['id' => 'uptime-kuma', 'label' => 'Uptime Kuma', 'description' => __('Self-hosted uptime monitor (Node.js).'), 'url' => 'https://github.com/louislam/uptime-kuma.git', 'branch' => 'master', 'subdirectory' => ''],
            ['id' => 'listmonk', 'label' => 'Listmonk', 'description' => __('Mailing-list and newsletter manager (Go).'), 'url' => 'https://github.com/knadh/listmonk.git', 'branch' => 'master', 'subdirectory' => ''],
            ['id' => 'vaultwarden', 'label' => 'Vaultwarden', 'description' => __('Bitwarden-compatible password vault (Rust).'), 'url' => 'https://github.com/dani-garcia/vaultwarden.git', 'branch' => 'main', 'subdirectory' => ''],
        ];
    }

    public function applyContainerOssPreset(string $id): void
    {
        $preset = collect($this->containerOssPresets())->firstWhere('id', $id);
        if (! $preset) {
            return;
        }
        $this->container_repo_source = 'manual';
        $this->container_repository_url = (string) $preset['url'];
        $this->container_repository_branch = (string) $preset['branch'];
        $this->container_repository_subdirectory = (string) $preset['subdirectory'];
        $this->container_inspection = [];
        $this->container_has_inspection = false;
        $this->resetErrorBag();
    }

    /**
     * Auto-inspect on field blur (per Q6-C). The blade wires this via
     * wire:change on the URL/branch/subdir fields; users see the detection
     * preview without clicking an "Inspect" button. Failures degrade to no
     * preview — submit still works.
     */
    public function inspectContainerRepository(LocalRepositoryInspector $repositoryInspector): void
    {
        $url = trim($this->resolvedContainerRepositoryUrl());
        if ($url === '') {
            $this->container_inspection = [];
            $this->container_has_inspection = false;

            return;
        }

        try {
            $inspection = $repositoryInspector->inspect(
                repositoryUrl: $url,
                branch: $this->container_repository_branch !== '' ? $this->container_repository_branch : 'main',
                subdirectory: $this->container_repository_subdirectory,
                userId: auth()->id(),
                sourceControlAccountId: $this->container_repo_source === 'provider' ? $this->container_source_control_account_id : null,
            );
        } catch (\Throwable) {
            $this->container_inspection = [];
            $this->container_has_inspection = false;

            return;
        }

        $this->container_inspection = $inspection;
        $this->container_has_inspection = true;
    }

    private function resolvedContainerRepositoryUrl(): string
    {
        return $this->container_repo_source === 'provider'
            ? $this->container_repository_selection
            : $this->container_repository_url;
    }

    /**
     * Container-mode submit. Validates the repo URL, ensures we have an
     * inspection payload, then hands off to FinalizeContainerCloudLaunchJob.
     * The job creates the Site row + chains ProvisionSiteJob +
     * RunSiteDeploymentJob once the host is ready (it polls if the host is
     * still provisioning, per Q5-B).
     */
    public function storeContainer(LocalRepositoryInspector $repositoryInspector): mixed
    {
        $this->authorize('update', $this->server);

        $rules = [
            'container_repo_source' => ['required', 'string', 'in:manual,provider'],
            'container_repository_branch' => ['required', 'string', 'max:120'],
            'container_repository_subdirectory' => ['nullable', 'string', 'max:255'],
        ];
        if ($this->container_repo_source === 'manual') {
            $rules['container_repository_url'] = ['required', 'string', 'max:500'];
        } else {
            $rules['container_source_control_account_id'] = ['required', 'string', 'max:26'];
            $rules['container_repository_selection'] = ['required', 'string', 'max:500'];
        }
        if ($this->server->hostKind() === Server::HOST_KIND_KUBERNETES) {
            $rules['container_kubernetes_namespace'] = ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'];
        }
        $this->validate($rules);

        // Re-inspect at submit time if the user never blurred the field — the
        // job needs the inspection payload to drive the Site creation.
        if (! $this->container_has_inspection) {
            $this->inspectContainerRepository($repositoryInspector);
        }
        if (! $this->container_has_inspection) {
            $this->addError('container_repository_url', __('Could not inspect this repository. Check the URL and branch.'));

            return null;
        }

        $user = auth()->user();
        $org = $user?->currentOrganization();
        if ($user === null || $org === null) {
            abort(403);
        }

        if ($this->siteQuotaReached($org)) {
            return null;
        }

        $this->authorize('create', Site::class);

        // K8s containers may target a different namespace than the server's
        // default. Stash the per-container namespace into the inspection
        // payload so the job + CreateContainerSiteFromInspection see it.
        $inspection = $this->container_inspection;
        if ($this->server->hostKind() === Server::HOST_KIND_KUBERNETES) {
            $inspection['detection']['kubernetes_namespace'] = $this->container_kubernetes_namespace !== ''
                ? $this->container_kubernetes_namespace
                : (string) data_get($this->server->meta, 'kubernetes.namespace', 'default');
        }

        $targetFamily = $this->server->hostKind() === Server::HOST_KIND_KUBERNETES
            ? 'cloud_kubernetes'
            : 'cloud_docker';

        // Seed the in-flight launch state on the server so the overview
        // banner has something to display while the job polls.
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['container_launch'] = [
            'status' => 'queued',
            'target_family' => $targetFamily,
            'repository_url' => (string) ($inspection['repository_url'] ?? $this->resolvedContainerRepositoryUrl()),
            'repository_branch' => $this->container_repository_branch,
            'repository_subdirectory' => $this->container_repository_subdirectory,
            'current_step_label' => __('Queued'),
            'summary' => __('Dply will create the site once the host is ready.'),
            'events' => [[
                'at' => now()->toIso8601String(),
                'level' => 'info',
                'message' => __('Container app launch queued from Sites/Create.'),
            ]],
        ];
        $this->server->forceFill(['meta' => $meta])->save();

        FinalizeContainerCloudLaunchJob::dispatch(
            (string) $this->server->id,
            (string) $user->id,
            (string) $org->id,
            $inspection,
            $targetFamily,
        );

        $isKubernetes = $this->server->hostKind() === Server::HOST_KIND_KUBERNETES;
        $destination = $isKubernetes ? 'servers.cluster' : 'servers.overview';
        session()->flash('success', $isKubernetes
            ? __('Container app queued. Watch progress on the cluster page.')
            : __('Container app queued. Watch progress on the server overview.'));

        return $this->redirect(route($destination, $this->server), navigate: true);
    }
}
