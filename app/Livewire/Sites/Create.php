<?php

namespace App\Livewire\Sites;

use App\Enums\ServerProvider;
use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Livewire\Forms\SiteCreateForm;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Deploy\ServerlessRepositoryCheckout;
use App\Services\Deploy\ServerlessRuntimeDetector;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\SiteProvisioner;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Support\HostnameValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public Server $server;

    public SiteCreateForm $form;

    /**
     * @var list<array{id: string, label: string}>
     */
    public array $phpVersions = [];

    /**
     * @var list<array{id: string, provider: string, label: string}>
     */
    public array $linkedSourceControlAccounts = [];

    /**
     * @var list<array{label: string, url: string, branch: string}>
     */
    public array $availableFunctionsRepositories = [];

    /**
     * @var array<string, mixed>
     */
    public array $functionsDetection = [];

    public bool $functionsOverridesTouched = false;

    public function mount(
        Server $server,
        ServerPhpManager $phpManager,
        SourceControlRepositoryBrowser $repositoryBrowser,
    ): void {
        $this->authorize('view', $server);
        $this->authorize('update', $server);

        $org = auth()->user()->currentOrganization();
        abort_if($org === null, 403);
        abort_if($server->organization_id === null, 403);
        if ($server->organization_id !== $org->id) {
            abort(404);
        }

        $this->authorize('create', Site::class);
        $this->server = $server;
        $this->form->applyDefaultsForType($this->form->type);
        if ($server->hostCapabilities()->supportsMachinePhpManagement()) {
            $phpData = $phpManager->siteCreationPhpData($server);
            $this->phpVersions = $phpData['available_versions'];
            $this->form->php_version = $phpData['preselected_version'];
        } else {
            $this->phpVersions = [];
            $this->form->php_version = '';
            $this->form->applyFunctionsDefaults();
            $this->loadFunctionsSourceControlState($repositoryBrowser);
        }

        $hostname = request()->query('hostname');
        if (is_string($hostname) && $hostname !== '') {
            $hostname = strtolower(trim($hostname));
            if (HostnameValidator::isValid($hostname)) {
                $this->form->primary_hostname = $hostname;
                if ($this->form->name === '') {
                    $label = explode('.', $hostname, 2)[0];
                    $this->form->name = $label !== '' ? $label : $hostname;
                }
            }
        }

        $this->form->applyPathDefaults();
    }

    public function updatedFormType(string $value): void
    {
        $this->form->applyDefaultsForType($value);
    }

    public function updatedFormFunctionsRepoSource(): void
    {
        if ($this->form->functions_repo_source === 'manual') {
            $this->form->functions_source_control_account_id = '';
            $this->form->functions_repository_selection = '';
            $this->availableFunctionsRepositories = [];

            return;
        }

        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        $this->form->functions_source_control_account_id = $this->linkedSourceControlAccounts[0]['id'];
        $this->updatedFormFunctionsSourceControlAccountId($this->form->functions_source_control_account_id);
    }

    public function updatedFormFunctionsSourceControlAccountId(string $value): void
    {
        $this->form->functions_source_control_account_id = $value;
        $this->form->functions_repository_selection = '';
        $this->availableFunctionsRepositories = [];

        if ($value === '') {
            return;
        }

        $account = auth()->user()->socialAccounts()->find($value);
        if (! $account) {
            return;
        }

        $this->availableFunctionsRepositories = app(SourceControlRepositoryBrowser::class)
            ->repositoriesForAccount($account);
    }

    public function updatedFormFunctionsRepositorySelection(string $value): void
    {
        foreach ($this->availableFunctionsRepositories as $repository) {
            if (($repository['url'] ?? null) !== $value) {
                continue;
            }

            $this->form->functions_repository_url = (string) $repository['url'];
            $this->form->functions_repository_branch = (string) ($repository['branch'] ?: 'main');
            $this->refreshFunctionsDetection();

            return;
        }
    }

    public function updatedFormFunctionsRepositoryUrl(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFormFunctionsRepositoryBranch(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFormFunctionsRepositorySubdirectory(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFormFunctionsBuildCommand(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFormFunctionsArtifactOutputPath(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFormFunctionsRuntime(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFormFunctionsEntrypoint(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFormPrimaryHostname(string $value): void
    {
        $this->form->primary_hostname = strtolower(trim($value));
        $this->form->applyPathDefaults();
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->form->applyFunctionsDefaults();
        }
    }

    public function updatedFormCustomizePaths(bool $value): void
    {
        $this->form->customize_paths = $value;

        if (! $value) {
            $this->form->applyPathDefaults();
        }
    }

    public function store(SiteProvisioner $siteProvisioner): mixed
    {
        $this->authorize('update', $this->server);
        $this->authorize('create', Site::class);

        $org = auth()->user()->currentOrganization();
        abort_if($org === null, 403);
        abort_if($this->server->organization_id === null, 403);
        abort_if($this->server->organization_id !== $org->id, 403);

        $phpVersionIds = array_column($this->phpVersions, 'id');
        $functionsHost = $this->server->hostCapabilities()->supportsFunctionDeploy();
        $dockerHost = $this->server->isDockerHost();
        $kubernetesHost = $this->server->isKubernetesCluster();
        $containerHost = $dockerHost || $kubernetesHost;

        $rules = [
            'name' => 'required|string|max:120',
            'type' => 'required|in:php,static,node',
            'document_root' => 'required|string|max:500',
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
                'required',
                'string',
                'max:255',
                'unique:site_domains,hostname',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid domain name like app.example.com.');
                    }
                },
            ],
        ];

        if ($this->form->type === 'php' && ! $functionsHost && ! $containerHost) {
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

        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'deploy_script_id' => $org?->default_site_script_id,
            'name' => $this->form->name,
            'slug' => Str::slug($this->form->name) ?: 'site',
            'type' => SiteType::from($this->form->type),
            'document_root' => $functionsHost
                ? ($this->server->isAwsLambdaHost()
                    ? '/lambda/'.trim($this->form->functions_entrypoint, '/')
                    : '/functions/'.$this->form->functions_entrypoint)
                : $this->form->document_root,
            'repository_path' => $functionsHost ? null : ($this->form->repository_path ?: null),
            'php_version' => $this->form->type === 'php' && ! $functionsHost && ! $containerHost ? $this->form->php_version : null,
            'app_port' => $this->form->type === 'node' ? $this->form->app_port : null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'git_repository_url' => $functionsHost ? trim($this->form->functions_repository_url) : null,
            'git_branch' => $functionsHost ? trim($this->form->functions_repository_branch) : 'main',
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

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => strtolower(trim($this->form->primary_hostname)),
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $site->loadMissing(['server', 'domains']);
        $siteProvisioner->markQueued($site);
        ProvisionSiteJob::dispatch($site->id);

        return $this->redirect(route('sites.show', [$this->server, $site]), navigate: true);
    }

    private function refreshFunctionsDetection(): void
    {
        if (! $this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return;
        }

        $repositoryUrl = trim($this->form->functions_repository_url);
        $branch = trim($this->form->functions_repository_branch);

        if ($repositoryUrl === '' || $branch === '') {
            $this->functionsDetection = [];

            return;
        }

        $checkout = null;

        try {
            $checkout = app(ServerlessRepositoryCheckout::class)->checkout(
                'preview-create-'.(string) auth()->id().'-'.md5($repositoryUrl.'|'.$branch.'|'.$this->form->functions_repository_subdirectory),
                $repositoryUrl,
                $branch,
                $this->form->functions_repository_subdirectory,
                auth()->id(),
                $this->form->functions_repo_source === 'provider' ? $this->form->functions_source_control_account_id : null,
            );

            $this->functionsDetection = app(ServerlessRuntimeDetector::class)->detect(
                $checkout['working_directory'],
                app(ServerlessTargetCapabilityResolver::class)->forServer($this->server),
            );

            if (! $this->functionsOverridesTouched) {
                $this->form->functions_runtime = (string) ($this->functionsDetection['runtime'] ?? $this->form->functions_runtime);
                $this->form->functions_entrypoint = (string) ($this->functionsDetection['entrypoint'] ?? $this->form->functions_entrypoint);
                $this->form->functions_build_command = (string) ($this->functionsDetection['build_command'] ?? $this->form->functions_build_command);
                $this->form->functions_artifact_output_path = (string) ($this->functionsDetection['artifact_output_path'] ?? $this->form->functions_artifact_output_path);
            }
        } catch (\Throwable $e) {
            $this->functionsDetection = [
                'framework' => 'unknown',
                'language' => 'unknown',
                'runtime' => '',
                'entrypoint' => '',
                'build_command' => '',
                'artifact_output_path' => '',
                'package' => 'default',
                'confidence' => 'low',
                'reasons' => [],
                'warnings' => [$e->getMessage()],
                'unsupported_for_target' => false,
            ];
        } finally {
            if (is_array($checkout) && isset($checkout['workspace_path']) && is_string($checkout['workspace_path'])) {
                app(ServerlessRepositoryCheckout::class)->cleanup($checkout['workspace_path']);
            }
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->loadCount('sites');

        return view('livewire.sites.create', [
            'phpVersions' => $this->phpVersions,
        ]);
    }

    private function loadFunctionsSourceControlState(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());

        if ($this->linkedSourceControlAccounts === []) {
            $this->form->functions_repo_source = 'manual';

            return;
        }

        if ($this->form->functions_repo_source === 'manual') {
            $this->form->functions_repo_source = 'provider';
        }

        if ($this->form->functions_source_control_account_id === '') {
            $this->form->functions_source_control_account_id = $this->linkedSourceControlAccounts[0]['id'];
        }

        $account = auth()->user()->socialAccounts()->find($this->form->functions_source_control_account_id);
        $this->availableFunctionsRepositories = $account
            ? $repositoryBrowser->repositoriesForAccount($account)
            : [];
    }
}
