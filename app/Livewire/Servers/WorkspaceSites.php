<?php

namespace App\Livewire\Servers;

use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Livewire\Forms\SiteCreateForm;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\SiteDomain;
use App\Services\Deploy\RuntimeAwareDeployStepDefaults;
use App\Services\Servers\ServerPhpManager;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Sites\InternalPortAllocator;
use App\Services\Sites\SiteProvisioner;
use App\Support\HostnameValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSites extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public SiteCreateForm $form;

    public bool $showAddSiteModal = false;

    /**
     * @var list<array{id: string, label: string}>
     */
    public array $phpVersions = [];

    #[Computed]
    public function canAddSite(): bool
    {
        if (! $this->server->isReady()) {
            return false;
        }

        return Gate::forUser(auth()->user())->allows('create', Site::class);
    }

    #[Computed]
    public function supportsQuickAdd(): bool
    {
        return ! $this->server->hostCapabilities()->supportsFunctionDeploy()
            && ! $this->server->isDockerHost()
            && ! $this->server->isKubernetesCluster();
    }

    public function mount(Server $server, ServerPhpManager $phpManager): void
    {
        $this->bootWorkspace($server);

        if ($server->hostCapabilities()->supportsMachinePhpManagement()) {
            $phpData = $phpManager->siteCreationPhpData($server);
            $this->phpVersions = $phpData['available_versions'];
            $this->form->php_version = $phpData['preselected_version'];
        }

        $this->form->applyDefaultsForType($this->form->type);
    }

    public function openAddSiteModal(): void
    {
        if (! $this->canAddSite) {
            return;
        }

        if (! $this->supportsQuickAdd) {
            $this->redirect(route('sites.create', $this->server), navigate: true);

            return;
        }

        $this->dispatch('open-modal', 'add-site-modal');
        $this->showAddSiteModal = true;
    }

    public function closeAddSiteModal(): void
    {
        $this->dispatch('close-modal', 'add-site-modal');
        $this->showAddSiteModal = false;
    }

    public function updatedFormPrimaryHostname(string $value): void
    {
        $value = strtolower(trim($value));
        $this->form->primary_hostname = $value;

        if ($this->form->name === '' && $value !== '') {
            $label = explode('.', $value, 2)[0];
            $this->form->name = $label !== '' ? $label : $value;
        }

        $this->form->applyPathDefaults();
    }

    public function updatedFormType(string $value): void
    {
        $this->form->applyDefaultsForType($value);
    }

    public function updatedFormFramework(string $value): void
    {
        $this->form->applyFrameworkDefaults($value);
    }

    public function updatedFormDocumentRoot(): void
    {
        $this->form->customize_paths = true;
    }

    public function updatedFormRepositoryPath(): void
    {
        $this->form->customize_paths = true;
    }

    public function addSite(SiteProvisioner $siteProvisioner): mixed
    {
        $this->authorize('update', $this->server);
        if (! $this->canAddSite) {
            return null;
        }

        if (! $this->supportsQuickAdd) {
            $hostname = strtolower(trim($this->form->primary_hostname));

            return $this->redirect(
                route('sites.create', $this->server).'?'.http_build_query(['hostname' => $hostname]),
                navigate: true,
            );
        }

        $rules = [
            'name' => 'required|string|max:120',
            'type' => 'required|in:php,static,node',
            'document_root' => 'required|string|max:500',
            'repository_path' => 'nullable|string|max:500',
            'app_port' => 'nullable|integer|min:1|max:65535',
            'framework' => 'nullable|string|in:,laravel,nodejs,statamic,craft,symfony,wordpress,october,cakephp3',
            'webserver_template' => 'nullable|string|max:64',
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

        $this->form->validate($rules);

        $effectiveRuntime = $this->form->type;
        $internalPort = null;
        if (! in_array($effectiveRuntime, ['php', 'static'], true)) {
            $internalPort = app(InternalPortAllocator::class)->allocate($this->server->id);
            if ($internalPort === null) {
                $this->addError(
                    'form.type',
                    __('No free internal port available on this server (range 30000–39999 is full).'),
                );

                return null;
            }
        }

        $org = $this->server->organization;

        // PHP version is intentionally not collected in the modal: the
        // server's preselected default is used at create time and the
        // runtime detector overrides it from the repo (composer.json
        // platform / .tool-versions / etc.) on first clone.
        $defaultPhpVersion = $this->form->type === 'php' && $this->form->php_version !== ''
            ? $this->form->php_version
            : null;

        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'deploy_script_id' => $org?->default_site_script_id,
            'name' => $this->form->name,
            'slug' => Str::slug($this->form->name) ?: 'site',
            'type' => SiteType::from($this->form->type),
            'runtime' => $this->form->type,
            'runtime_version' => $defaultPhpVersion,
            'internal_port' => $internalPort,
            'document_root' => $this->form->document_root,
            'repository_path' => $this->form->repository_path ?: null,
            'app_port' => $this->form->type === 'node' ? $this->form->app_port : null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => 'simple',
            'releases_to_keep' => 5,
            'laravel_scheduler' => false,
            'deployment_environment' => 'production',
            'restart_supervisor_programs_after_deploy' => false,
            'meta' => [
                'framework' => $this->form->framework ?: null,
                'webserver_template' => $this->form->webserver_template ?: 'default',
                'create_options' => [
                    'create_system_user' => $this->form->create_system_user,
                    'create_staging_site' => $this->form->create_staging_site,
                    'use_as_redirect_domain' => $this->form->use_as_redirect_domain,
                ],
                // Marker for the runtime detector: the Site was created
                // without an explicit runtime/version pin; once the repo
                // is cloned, detection should fill these from the source.
                'runtime_detection_pending' => true,
            ],
        ]);

        $site->ensureUniqueSlug();
        $site->save();

        $defaults = app(RuntimeAwareDeployStepDefaults::class)->defaultsFor($site->runtime, null);
        foreach ($defaults as $step) {
            SiteDeployStep::create([
                'site_id' => $site->id,
                'sort_order' => $step['sort_order'],
                'step_type' => $step['step_type'],
                'phase' => $step['phase'],
                'custom_command' => $step['custom_command'] ?? null,
                'timeout_seconds' => $step['timeout_seconds'],
            ]);
        }

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

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['sites.domains']);

        return view('livewire.servers.workspace-sites', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
