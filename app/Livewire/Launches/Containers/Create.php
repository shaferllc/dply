<?php

namespace App\Livewire\Launches\Containers;

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
class Create extends Component
{
    public string $repo_source = 'manual';

    public string $repository_url = '';

    public string $repository_branch = 'main';

    public string $repository_subdirectory = '';

    public string $source_control_account_id = '';

    public string $repository_selection = '';

    public string $target_family = 'digitalocean_docker';

    public string $provider_credential_id = '';

    public string $cloud_region = '';

    public string $cloud_size = '';

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
        if ($this->target_family === '') {
            $this->target_family = $this->defaultTargetForDetection(
                (string) ($inspection['detection']['target_kind'] ?? ''),
            );
        }
        $this->kubernetes_namespace = (string) ($inspection['detection']['kubernetes_namespace'] ?: 'default');
        $this->syncCloudDefaults();
        $this->seedServerNameFromInspection();
    }

    public function updatedTargetFamily(string $value): void
    {
        $this->provider_credential_id = '';
        $this->cloud_region = '';
        $this->cloud_size = '';
        $this->syncCloudDefaults();
        $this->seedServerNameFromInspection();
    }

    public function updatedProviderCredentialId(string $value): void
    {
        $this->cloud_region = '';
        $this->cloud_size = '';
        $this->syncCloudDefaults();
    }

    public function updatedInspection(): void
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

        return $this->redirect(route('servers.overview', $server), navigate: true);
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
                ->filter(fn (array $badge, string $id): bool => ! str_starts_with($id, 'local_'))
                ->contains(fn (array $badge): bool => $badge['linked'] === true),
            'localTargetsEnabled' => self::localTargetsEnabled(),
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
            if (str_starts_with($id, 'local_')) {
                $badges[$id] = ['linked' => true];
            } elseif (str_starts_with($id, 'digitalocean_')) {
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
            'digitalocean_docker' => __('Single Droplet running Docker'),
            'digitalocean_kubernetes' => __('DOKS managed cluster'),
            'aws_docker' => __('EC2 instance running Docker'),
            'aws_kubernetes' => __('EKS managed cluster'),
            'local_orbstack_docker' => __('Local OrbStack Docker (testing only)'),
            'local_orbstack_kubernetes' => __('Local OrbStack Kubernetes (testing only)'),
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
     * Production targets are remote-only. Local Docker / local Kubernetes
     * launchers stay available behind DPLY_ENABLE_LOCAL_DOCKER_LAUNCH for
     * dogfooding (default on in local env, off everywhere else) — they
     * point at 127.0.0.1 and would be misleading to a customer.
     *
     * @return list<array{id: string, label: string}>
     */
    private function targetOptions(): array
    {
        $options = [
            ['id' => 'digitalocean_docker', 'label' => __('Remote Docker (DigitalOcean)')],
            ['id' => 'digitalocean_kubernetes', 'label' => __('Remote Kubernetes (DigitalOcean)')],
            ['id' => 'aws_docker', 'label' => __('Remote Docker (AWS)')],
            ['id' => 'aws_kubernetes', 'label' => __('Remote Kubernetes (AWS)')],
        ];

        if (self::localTargetsEnabled()) {
            array_unshift(
                $options,
                ['id' => 'local_orbstack_docker', 'label' => __('Local Docker (testing only)')],
                ['id' => 'local_orbstack_kubernetes', 'label' => __('Local Kubernetes (testing only)')],
            );
        }

        return $options;
    }

    public static function localTargetsEnabled(): bool
    {
        return filter_var(
            config('launches.local_docker_enabled', app()->environment('local')),
            FILTER_VALIDATE_BOOL,
        );
    }

    private function defaultTargetForDetection(string $targetKind): string
    {
        if ($targetKind === 'kubernetes') {
            return self::localTargetsEnabled() ? 'local_orbstack_kubernetes' : 'digitalocean_kubernetes';
        }

        return self::localTargetsEnabled() ? 'local_orbstack_docker' : 'digitalocean_docker';
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
        $rules = [
            'target_family' => ['required', 'string', 'in:'.implode(',', $allowedFamilies)],
            'server_name' => ['required', 'string', 'max:120'],
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
            'name' => $this->server_name !== '' ? $this->server_name : $nameBase.'-'.$this->target_family,
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

        $name = $this->server_name !== '' ? $this->server_name : $nameBase.'-'.str_replace('_', '-', $this->target_family);

        if ($this->target_family === 'digitalocean_docker') {
            $server = $user->servers()->create([
                'organization_id' => $organization->id,
                'name' => $name,
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
                'name' => $name,
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
