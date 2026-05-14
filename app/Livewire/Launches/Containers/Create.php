<?php

namespace App\Livewire\Launches\Containers;

use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Sites\CreateContainerSiteFromInspection;
use App\Enums\ServerProvider;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\SiteDeployment;
use App\Services\Deploy\LocalRepositoryInspector;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Bus;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Containers launcher: inspect a repo first, then route the user to one of
 * two paths:
 *   - Docker host → writes the inspection into the server-create wizard
 *     draft and redirects to /servers/create?host_target=docker. The wizard
 *     handles provider/account/region/size with its rich UI; on success its
 *     StepReview dispatches FinalizeContainerCloudLaunchJob.
 *   - Kubernetes (DOKS / EKS) → stays on this page with an inline form.
 *     Managed K8s clusters don't fit the wizard's VM-provisioning model,
 *     so the in-place flow remains.
 */
#[Layout('layouts.app')]
class Create extends Component
{
    public string $repo_source = 'manual';

    public string $repository_url = '';

    public string $repository_branch = 'main';

    public string $repository_subdirectory = '';

    public string $source_control_account_id = '';

    public string $repository_selection = '';

    /** Path selection after inspection: 'docker' (→ wizard) or 'kubernetes' (→ inline form). */
    public string $path = 'docker';

    /** K8s-only — the wizard owns Docker target selection now. */
    public string $target_family = 'digitalocean_kubernetes';

    public string $provider_credential_id = '';

    public string $cloud_region = '';

    public string $cluster_name = '';

    public string $kubernetes_namespace = 'default';

    public string $server_name = '';

    public array $linkedSourceControlAccounts = [];

    public array $availableRepositories = [];

    public array $inspection = [];

    public bool $has_inspection = false;

    public function mount(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());
        if ($this->linkedSourceControlAccounts !== []) {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            $this->updatedSourceControlAccountId($this->source_control_account_id);
        }
    }

    public function updatedRepoSource(string $value): void
    {
        if ($value === 'manual') {
            $this->repository_selection = '';
        } elseif ($this->source_control_account_id !== '') {
            $this->updatedSourceControlAccountId($this->source_control_account_id);
        }
    }

    public function updatedSourceControlAccountId(string $value): void
    {
        $this->source_control_account_id = $value;
        $this->repository_selection = '';
        $account = auth()->user()?->socialAccounts()->find($value);
        $this->availableRepositories = $account
            ? app(SourceControlRepositoryBrowser::class)->repositoriesForAccount($account)
            : [];

        if ($this->availableRepositories !== []) {
            $first = $this->availableRepositories[0];
            $this->repository_selection = (string) $first['url'];
            $this->repository_branch = (string) ($first['branch'] ?? 'main');
        }
    }

    public function inspectRepository(LocalRepositoryInspector $repositoryInspector): void
    {
        $user = auth()->user();
        $validated = $this->validate($this->inspectionRules());

        $inspection = $repositoryInspector->inspect(
            repositoryUrl: $this->resolvedRepositoryUrl(),
            branch: $validated['repository_branch'],
            subdirectory: $validated['repository_subdirectory'],
            userId: $user?->getKey(),
            sourceControlAccountId: $this->repo_source === 'provider' ? $validated['source_control_account_id'] : null,
        );

        $this->inspection = $inspection;
        $this->has_inspection = true;

        // Auto-pick the path based on inspector detection. Kubernetes-manifest repos
        // hint K8s; everything else (Dockerfile-only, generic) flows to the Docker wizard.
        $detected = (string) ($inspection['detection']['target_kind'] ?? '');
        $this->path = $detected === 'kubernetes' ? 'kubernetes' : 'docker';

        $this->kubernetes_namespace = (string) ($inspection['detection']['kubernetes_namespace'] ?: 'default');
        $this->syncCloudDefaults();
        $this->seedServerNameFromInspection();
    }

    public function updatedTargetFamily(string $value): void
    {
        $this->provider_credential_id = '';
        $this->cloud_region = '';
        $this->syncCloudDefaults();
        $this->seedServerNameFromInspection();
    }

    public function updatedProviderCredentialId(string $value): void
    {
        $this->cloud_region = '';
        $this->syncCloudDefaults();
    }

    public function updatedInspection(): void
    {
        $this->seedServerNameFromInspection();
    }

    public function updatedPath(string $value): void
    {
        $this->seedServerNameFromInspection();
    }

    private function seedServerNameFromInspection(): void
    {
        if ($this->server_name !== '') {
            return;
        }
        $slug = (string) ($this->inspection['slug'] ?? '');
        if ($slug === '') {
            return;
        }
        $this->server_name = $slug.'-'.str_replace('_', '-', $this->target_family);
    }

    /**
     * Docker tile path: stash the inspected repo info into the wizard draft and
     * redirect to /servers/create?host_target=docker. The wizard's StepReview
     * later reads draft.payload._container_launch and dispatches the finalizer.
     */
    public function goToDockerWizard(): mixed
    {
        if (! $this->has_inspection) {
            $this->addError('repository_url', __('Inspect the repository before continuing.'));

            return null;
        }

        $user = auth()->user();
        $organization = $user?->currentOrganization();
        abort_if($organization === null, 403);

        $draft = ServerCreateDraft::query()->firstOrNew([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ]);
        $payload = is_array($draft->payload ?? null) ? $draft->payload : [];
        $payload['_container_launch'] = [
            'inspection' => $this->inspection,
            'repository_url' => $this->resolvedRepositoryUrl(),
            'repository_branch' => $this->repository_branch,
            'repository_subdirectory' => $this->repository_subdirectory,
            'slug' => (string) ($this->inspection['slug'] ?? ''),
            'target_family' => 'cloud_docker',
        ];
        $draft->payload = $payload;
        if ((int) ($draft->step ?? 0) < 1) {
            $draft->step = 1;
        }
        $draft->bumpExpiry();
        $draft->save();

        return $this->redirect(route('servers.create', ['host_target' => 'docker']), navigate: true);
    }

    /**
     * Kubernetes path: in-place launch — creates a managed-cluster Server row,
     * the site workspace, and queues provision + first deployment.
     */
    public function launch(LocalRepositoryInspector $repositoryInspector, CreateContainerSiteFromInspection $siteCreator): mixed
    {
        $user = auth()->user();
        $organization = $user?->currentOrganization();
        abort_if($organization === null, 403);

        if (! $this->has_inspection) {
            $this->inspectRepository($repositoryInspector);
        }

        $this->validate($this->launchRules());
        $inspection = $this->inspection;
        $nameBase = (string) ($inspection['slug'] ?? 'project');

        $server = $this->storeKubernetesServer($user, $organization, $nameBase);
        $site = $siteCreator->handle($server, $user, $organization, $inspection, $this->target_family);

        Bus::chain([
            new ProvisionSiteJob($site->id),
            new RunSiteDeploymentJob($site, SiteDeployment::TRIGGER_API, null, (string) $user->id),
        ])->dispatch();

        return $this->redirect(route('sites.show', [$server, $site]), navigate: true);
    }

    public function render(): View
    {
        $badges = $this->targetBadges();

        return view('livewire.launches.containers.create', [
            'targetOptions' => $this->targetOptions(),
            'providerCredentials' => $this->providerCredentials(),
            'cloudCatalog' => $this->cloudCatalog(),
            'connectCredentialUrl' => route('credentials.index'),
            'ossPresets' => $this->ossPresets(),
            'targetBadges' => $badges,
            'targetDescriptions' => $this->targetDescriptions(),
            'hasAnyCloudCredentials' => collect($badges)
                ->contains(fn (array $badge): bool => $badge['linked'] === true),
        ]);
    }

    /**
     * @return array<string, array{linked: bool}>
     */
    private function targetBadges(): array
    {
        $organization = auth()->user()?->currentOrganization();
        $doLinked = $organization
            ? GetProviderCredentialsForServerType::run($organization, 'digitalocean')->isNotEmpty()
            : false;
        $awsLinked = $organization
            ? GetProviderCredentialsForServerType::run($organization, 'aws')->isNotEmpty()
            : false;

        $badges = [];
        foreach ($this->targetOptions() as $option) {
            $id = $option['id'];
            if (str_starts_with($id, 'digitalocean_')) {
                $badges[$id] = ['linked' => $doLinked];
            } elseif (str_starts_with($id, 'aws_')) {
                $badges[$id] = ['linked' => $awsLinked];
            } else {
                $badges[$id] = ['linked' => false];
            }
        }

        return $badges;
    }

    /**
     * @return array<string, string>
     */
    private function targetDescriptions(): array
    {
        return [
            'digitalocean_kubernetes' => __('DOKS managed cluster'),
            'aws_kubernetes' => __('EKS managed cluster'),
        ];
    }

    public function applyPreset(string $id): void
    {
        $preset = collect($this->ossPresets())->firstWhere('id', $id);
        if (! $preset) {
            return;
        }

        $this->repo_source = 'manual';
        $this->repository_url = (string) $preset['url'];
        $this->repository_branch = (string) $preset['branch'];
        $this->repository_subdirectory = (string) $preset['subdirectory'];
        $this->has_inspection = false;
        $this->inspection = [];
        $this->resetErrorBag();
    }

    /**
     * Open-source repos with known-good Dockerfiles, surfaced as
     * one-click presets so the launcher can be exercised against
     * real apps without hunting down a sample.
     *
     * @return list<array{id: string, label: string, description: string, url: string, branch: string, subdirectory: string}>
     */
    private function ossPresets(): array
    {
        return [
            [
                'id' => 'plausible',
                'label' => 'Plausible Analytics',
                'description' => __('Privacy-friendly web analytics (Elixir).'),
                'url' => 'https://github.com/plausible/analytics.git',
                'branch' => 'master',
                'subdirectory' => '',
            ],
            [
                'id' => 'uptime-kuma',
                'label' => 'Uptime Kuma',
                'description' => __('Self-hosted uptime monitor (Node.js).'),
                'url' => 'https://github.com/louislam/uptime-kuma.git',
                'branch' => 'master',
                'subdirectory' => '',
            ],
            [
                'id' => 'listmonk',
                'label' => 'Listmonk',
                'description' => __('Mailing-list and newsletter manager (Go).'),
                'url' => 'https://github.com/knadh/listmonk.git',
                'branch' => 'master',
                'subdirectory' => '',
            ],
            [
                'id' => 'vaultwarden',
                'label' => 'Vaultwarden',
                'description' => __('Bitwarden-compatible password vault (Rust).'),
                'url' => 'https://github.com/dani-garcia/vaultwarden.git',
                'branch' => 'main',
                'subdirectory' => '',
            ],
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function targetOptions(): array
    {
        return [
            ['id' => 'digitalocean_kubernetes', 'label' => __('DOKS (DigitalOcean)')],
            ['id' => 'aws_kubernetes', 'label' => __('EKS (AWS)')],
        ];
    }

    private function resolvedRepositoryUrl(): string
    {
        return $this->repo_source === 'provider' ? $this->repository_selection : $this->repository_url;
    }

    private function inspectionRules(): array
    {
        return [
            'repo_source' => ['required', 'string', 'in:manual,provider'],
            'repository_url' => $this->repo_source === 'manual' ? ['required', 'string', 'max:500'] : ['nullable', 'string', 'max:500'],
            'source_control_account_id' => $this->repo_source === 'provider' ? ['required', 'string', 'max:26'] : ['nullable', 'string', 'max:26'],
            'repository_selection' => $this->repo_source === 'provider' ? ['required', 'string', 'max:500'] : ['nullable', 'string', 'max:500'],
            'repository_branch' => ['required', 'string', 'max:120'],
            'repository_subdirectory' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function launchRules(): array
    {
        $allowedFamilies = collect($this->targetOptions())->pluck('id')->all();

        return [
            'target_family' => ['required', 'string', 'in:'.implode(',', $allowedFamilies)],
            'server_name' => ['required', 'string', 'max:120'],
            'provider_credential_id' => ['required', 'string', 'max:26'],
            'cluster_name' => ['nullable', 'string', 'max:255'],
            'kubernetes_namespace' => ['required', 'string', 'max:63'],
        ];
    }

    private function syncCloudDefaults(): void
    {
        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            return;
        }

        $providerKey = str_starts_with($this->target_family, 'aws_') ? 'aws' : 'digitalocean';
        $credentials = GetProviderCredentialsForServerType::run($organization, $providerKey);
        if ($this->provider_credential_id === '' && $credentials->isNotEmpty()) {
            $this->provider_credential_id = (string) $credentials->first()->id;
        }

        $catalog = $this->cloudCatalog();
        $this->cloud_region = $this->cloud_region !== '' ? $this->cloud_region : (string) ($catalog['regions'][0]['value'] ?? '');
        $this->cluster_name = $this->cluster_name !== '' ? $this->cluster_name : (($this->inspection['slug'] ?? 'app').'-cluster');
    }

    private function providerCredentials(): array
    {
        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            return [];
        }

        $providerKey = str_starts_with($this->target_family, 'aws_') ? 'aws' : 'digitalocean';

        return GetProviderCredentialsForServerType::run($organization, $providerKey)
            ->map(fn (ProviderCredential $credential): array => ['id' => (string) $credential->id, 'name' => $credential->name])
            ->all();
    }

    private function cloudCatalog(): array
    {
        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            return ['regions' => [], 'sizes' => []];
        }

        $providerKey = str_starts_with($this->target_family, 'aws_') ? 'aws' : 'digitalocean';

        return ResolveServerCreateCatalog::run($organization, $providerKey, $this->provider_credential_id, $this->cloud_region);
    }

    private function storeKubernetesServer($user, $organization, string $nameBase): Server
    {
        $credential = ProviderCredential::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($this->provider_credential_id);

        $name = $this->server_name !== '' ? $this->server_name : $nameBase.'-'.str_replace('_', '-', $this->target_family);

        return $user->servers()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'provider' => str_starts_with($this->target_family, 'aws_') ? ServerProvider::Aws : ServerProvider::DigitalOcean,
            'provider_credential_id' => $credential->id,
            'region' => $this->cloud_region !== '' ? $this->cloud_region : null,
            'ssh_port' => 22,
            'ssh_user' => 'kubernetes',
            'status' => Server::STATUS_READY,
            'health_status' => Server::HEALTH_REACHABLE,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
                'kubernetes' => [
                    'provider' => str_starts_with($this->target_family, 'aws_') ? 'aws' : 'digitalocean',
                    'cluster_name' => $this->cluster_name,
                    'namespace' => $this->kubernetes_namespace,
                    'context' => config('kubernetes.context'),
                    'kubeconfig_path' => config('kubernetes.kubeconfig_path'),
                ],
            ],
        ]);
    }
}
