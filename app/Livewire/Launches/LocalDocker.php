<?php

namespace App\Livewire\Launches;

use App\Actions\Servers\GetProviderCredentialsForServerType;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Actions\Sites\CreateContainerSiteFromInspection;
use App\Enums\ServerProvider;
use App\Jobs\FinalizeContainerCloudLaunchJob;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\SiteDeployment;
use App\Services\Deploy\LocalRepositoryInspector;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Bus;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class LocalDocker extends Component
{
    public string $repo_source = 'manual';

    public string $repository_url = '';

    public string $repository_branch = 'main';

    public string $repository_subdirectory = '';

    public string $source_control_account_id = '';

    public string $repository_selection = '';

    public string $target_family = '';

    public string $provider_credential_id = '';

    public string $cloud_region = '';

    public string $cloud_size = '';

    public string $cluster_name = '';

    public string $kubernetes_namespace = 'default';

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
        $this->target_family = $this->target_family !== ''
            ? $this->target_family
            : ($inspection['detection']['target_kind'] === 'kubernetes' ? 'local_orbstack_kubernetes' : 'local_orbstack_docker');
        $this->kubernetes_namespace = (string) ($inspection['detection']['kubernetes_namespace'] ?: 'default');
        $this->syncCloudDefaults();
    }

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

        if (str_starts_with($this->target_family, 'local_')) {
            $server = $this->storeLocalServer($user, $organization, $nameBase);
            $site = $siteCreator->handle($server, $user, $organization, $inspection, $this->target_family);

            Bus::chain([
                new ProvisionSiteJob($site->id),
                new RunSiteDeploymentJob($site, SiteDeployment::TRIGGER_API, null, (string) $user->id),
            ])->dispatch();

            return $this->redirect(route('sites.show', [$server, $site]), navigate: true);
        }

        $server = $this->storeCloudServer($user, $organization, $nameBase);

        if (str_contains($this->target_family, 'kubernetes')) {
            $site = $siteCreator->handle($server, $user, $organization, $inspection, $this->target_family);

            Bus::chain([
                new ProvisionSiteJob($site->id),
                new RunSiteDeploymentJob($site, SiteDeployment::TRIGGER_API, null, (string) $user->id),
            ])->dispatch();

            return $this->redirect(route('sites.show', [$server, $site]), navigate: true);
        }

        FinalizeContainerCloudLaunchJob::dispatch(
            (string) $server->id,
            (string) $user->id,
            (string) $organization->id,
            $inspection,
            $this->target_family,
        );

        return $this->redirect(route('servers.show', $server), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.launches.local-docker', [
            'targetOptions' => [
                ['id' => 'local_orbstack_docker', 'label' => __('Local Docker')],
                ['id' => 'local_orbstack_kubernetes', 'label' => __('Local Kubernetes')],
                ['id' => 'digitalocean_docker', 'label' => __('Remote Docker (DigitalOcean)')],
                ['id' => 'digitalocean_kubernetes', 'label' => __('Remote Kubernetes (DigitalOcean)')],
                ['id' => 'aws_docker', 'label' => __('Remote Docker (AWS)')],
                ['id' => 'aws_kubernetes', 'label' => __('Remote Kubernetes (AWS)')],
            ],
            'providerCredentials' => $this->providerCredentials(),
            'cloudCatalog' => $this->cloudCatalog(),
        ]);
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
        $rules = [
            'target_family' => ['required', 'string', 'in:local_orbstack_docker,local_orbstack_kubernetes,digitalocean_docker,digitalocean_kubernetes,aws_docker,aws_kubernetes'],
        ];

        if (str_starts_with($this->target_family, 'digitalocean_') || str_starts_with($this->target_family, 'aws_')) {
            $rules['provider_credential_id'] = ['required', 'string', 'max:26'];
        }

        if (! str_starts_with($this->target_family, 'local_') && str_ends_with($this->target_family, '_docker')) {
            $rules['cloud_region'] = ['required', 'string', 'max:120'];
            $rules['cloud_size'] = ['required', 'string', 'max:120'];
        }

        if (str_contains($this->target_family, 'kubernetes')) {
            $rules['cluster_name'] = ['nullable', 'string', 'max:255'];
            $rules['kubernetes_namespace'] = ['required', 'string', 'max:63'];
        }

        return $rules;
    }

    private function syncCloudDefaults(): void
    {
        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            return;
        }

        if (str_starts_with($this->target_family, 'digitalocean_')) {
            $credentials = GetProviderCredentialsForServerType::run($organization, 'digitalocean');
            if ($this->provider_credential_id === '' && $credentials->isNotEmpty()) {
                $this->provider_credential_id = (string) $credentials->first()->id;
            }
        } elseif (str_starts_with($this->target_family, 'aws_')) {
            $credentials = GetProviderCredentialsForServerType::run($organization, 'aws');
            if ($this->provider_credential_id === '' && $credentials->isNotEmpty()) {
                $this->provider_credential_id = (string) $credentials->first()->id;
            }
        }

        $catalog = $this->cloudCatalog();
        $this->cloud_region = $this->cloud_region !== '' ? $this->cloud_region : (string) ($catalog['regions'][0]['value'] ?? '');
        $this->cloud_size = $this->cloud_size !== '' ? $this->cloud_size : (string) ($catalog['sizes'][0]['value'] ?? '');
        $this->cluster_name = $this->cluster_name !== '' ? $this->cluster_name : (($this->inspection['slug'] ?? 'app').'-cluster');
    }

    private function providerCredentials(): array
    {
        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            return [];
        }

        if (str_starts_with($this->target_family, 'digitalocean_')) {
            return GetProviderCredentialsForServerType::run($organization, 'digitalocean')
                ->map(fn (ProviderCredential $credential): array => ['id' => (string) $credential->id, 'name' => $credential->name])
                ->all();
        }

        if (str_starts_with($this->target_family, 'aws_')) {
            return GetProviderCredentialsForServerType::run($organization, 'aws')
                ->map(fn (ProviderCredential $credential): array => ['id' => (string) $credential->id, 'name' => $credential->name])
                ->all();
        }

        return [];
    }

    private function cloudCatalog(): array
    {
        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            return ['regions' => [], 'sizes' => []];
        }

        if (str_starts_with($this->target_family, 'digitalocean_')) {
            return ResolveServerCreateCatalog::run($organization, 'digitalocean', $this->provider_credential_id, $this->cloud_region);
        }

        if (str_starts_with($this->target_family, 'aws_')) {
            return ResolveServerCreateCatalog::run($organization, 'aws', $this->provider_credential_id, $this->cloud_region);
        }

        return ['regions' => [], 'sizes' => []];
    }

    private function storeLocalServer($user, $organization, string $nameBase): Server
    {
        $mode = str_contains($this->target_family, 'kubernetes') ? 'kubernetes' : 'docker';
        $meta = [
            'host_kind' => $mode === 'kubernetes' ? Server::HOST_KIND_KUBERNETES : Server::HOST_KIND_DOCKER,
            'local_runtime' => [
                'provider' => 'orbstack',
                'mode' => $mode,
                'auto_created' => true,
                'detected_at' => now()->toIso8601String(),
            ],
        ];

        if ($mode === 'kubernetes') {
            $meta['kubernetes'] = [
                'namespace' => $this->kubernetes_namespace,
                'context' => config('kubernetes.context'),
                'kubeconfig_path' => config('kubernetes.kubeconfig_path'),
            ];
        }

        return $user->servers()->create([
            'organization_id' => $organization->id,
            'name' => $nameBase.'-'.$this->target_family,
            'provider' => ServerProvider::Custom,
            'ip_address' => '127.0.0.1',
            'ssh_port' => 2222,
            'ssh_user' => 'dplytest',
            'status' => Server::STATUS_READY,
            'health_status' => Server::HEALTH_REACHABLE,
            'meta' => $meta,
        ]);
    }

    private function storeCloudServer($user, $organization, string $nameBase): Server
    {
        $credential = ProviderCredential::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($this->provider_credential_id);
        $containerLaunchMeta = [
            'status' => 'waiting_for_server',
            'target_family' => $this->target_family,
            'repository_url' => $this->resolvedRepositoryUrl(),
            'repository_branch' => $this->repository_branch,
            'repository_subdirectory' => $this->repository_subdirectory,
            'current_step_label' => 'Provisioning server',
            'summary' => 'Dply is provisioning the remote server before it can create the site workspace.',
            'events' => [[
                'at' => now()->toIso8601String(),
                'level' => 'info',
                'message' => 'Remote container launch queued. Dply will create the site after the server is ready.',
                'context' => array_filter([
                    'target_family' => $this->target_family,
                    'repository_branch' => $this->repository_branch,
                    'repository_subdirectory' => $this->repository_subdirectory,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ]],
        ];

        if ($this->target_family === 'digitalocean_docker') {
            $server = $user->servers()->create([
                'organization_id' => $organization->id,
                'name' => $nameBase.'-digitalocean-docker',
                'provider' => ServerProvider::DigitalOcean,
                'provider_credential_id' => $credential->id,
                'region' => $this->cloud_region,
                'size' => $this->cloud_size,
                'meta' => [
                    'host_kind' => Server::HOST_KIND_DOCKER,
                    'container_launch' => $containerLaunchMeta,
                ],
                'status' => Server::STATUS_PENDING,
            ]);
            ProvisionDigitalOceanDropletJob::dispatch($server);

            return $server;
        }

        if ($this->target_family === 'aws_docker') {
            $server = $user->servers()->create([
                'organization_id' => $organization->id,
                'name' => $nameBase.'-aws-docker',
                'provider' => ServerProvider::Aws,
                'provider_credential_id' => $credential->id,
                'region' => $this->cloud_region,
                'size' => $this->cloud_size,
                'meta' => [
                    'host_kind' => Server::HOST_KIND_DOCKER,
                    'container_launch' => $containerLaunchMeta,
                ],
                'status' => Server::STATUS_PENDING,
            ]);
            ProvisionAwsEc2ServerJob::dispatch($server);

            return $server;
        }

        return $user->servers()->create([
            'organization_id' => $organization->id,
            'name' => $nameBase.'-'.$this->target_family,
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
