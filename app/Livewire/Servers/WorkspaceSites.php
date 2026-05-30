<?php

namespace App\Livewire\Servers;

use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\EnforcesSiteQuota;
use App\Livewire\Forms\SiteCreateForm;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\SiteDomain;
use App\Services\Deploy\RuntimeAwareDeployStepDefaults;
use App\Services\Servers\ServerBulkSiteActions;
use App\Services\Servers\ServerPhpManager;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Sites\InternalPortAllocator;
use App\Services\Sites\SiteProvisioner;
use App\Support\HostnameValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSites extends Component
{
    use DispatchesToastNotifications;
    use EnforcesSiteQuota;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public SiteCreateForm $form;

    public bool $showAddSiteModal = false;

    public bool $showRedeployAllModal = false;

    /**
     * @var list<string>
     */
    public array $selectedSiteIds = [];

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

    /**
     * Human-readable reason the Add site button is disabled, or '' when
     * it's enabled. Surfaces the real blocker (server not ready, deployer
     * role, or plan site cap) inline so operators don't have to guess.
     */
    #[Computed]
    public function addSiteBlockedReason(): string
    {
        if (! $this->server->isReady()) {
            return __('This server is still provisioning — site creation unlocks once it reaches the ready state.');
        }

        $user = auth()->user();
        $org = $user?->currentOrganization();

        if ($org === null) {
            return __('No active organization is selected for your account.');
        }

        if ($org->userIsDeployer($user)) {
            return __('Your role on this organization (deployer) cannot create new sites. Ask an owner or admin.');
        }

        if (! $org->canCreateSite()) {
            return __('You\'ve hit your plan\'s site limit (:used / :max). Delete an existing site or upgrade to add more.', [
                'used' => $org->sites()->count(),
                'max' => $org->maxSitesDisplay(),
            ]);
        }

        return '';
    }

    #[Computed]
    public function supportsQuickAdd(): bool
    {
        // When the choose-app flow is enabled, VM hosts skip the inline
        // quick-add modal and go through the full create page (bare site →
        // choose-app picker). See docs/CHOOSE_APP_FLOW.md.
        if (config('dply.choose_app_enabled') && $this->server->isVmHost()) {
            return false;
        }

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

    public function selectAllSites(): void
    {
        $this->selectedSiteIds = $this->server->sites
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public function clearSiteSelection(): void
    {
        $this->selectedSiteIds = [];
    }

    public function openRedeployAllModal(): void
    {
        abort_unless(Feature::active('workspace.bulk_site_actions'), 404);
        $this->authorize('update', $this->server);

        if ($this->selectedBulkPreview['redeploy_count'] === 0) {
            $this->toastError(__('No deployable sites are ready in your selection.'));

            return;
        }

        $this->showRedeployAllModal = true;
        $this->dispatch('open-modal', 'redeploy-all-sites');
    }

    public function closeRedeployAllModal(): void
    {
        $this->showRedeployAllModal = false;
        $this->dispatch('close-modal', 'redeploy-all-sites');
    }

    public function confirmRedeployAll(ServerBulkSiteActions $bulkActions): void
    {
        abort_unless(Feature::active('workspace.bulk_site_actions'), 404);
        $this->authorize('update', $this->server);

        $preview = $this->selectedBulkPreview;
        if ($preview['redeploy_count'] === 0) {
            $this->closeRedeployAllModal();
            $this->toastError(__('No deployable sites are ready in your selection.'));

            return;
        }

        $result = $bulkActions->redeploySelected($this->server, $this->selectedSiteIds, auth()->user());
        $this->closeRedeployAllModal();
        $this->selectedSiteIds = [];

        $this->toastSuccess(trans_choice(
            'Queued redeploy for :count site|Queued redeploy for :count sites',
            $result['queued'],
            ['count' => $result['queued']],
        ));
    }

    /**
     * Bulk-action preview scoped to the current row selection.
     *
     * @return array{redeploy_count: int, renewable_count: int, site_names: list<string>}
     */
    #[Computed]
    public function selectedBulkPreview(): array
    {
        if (! Feature::active('workspace.bulk_site_actions') || ! $this->server->isVmHost()) {
            return [
                'redeploy_count' => 0,
                'renewable_count' => 0,
                'site_names' => [],
            ];
        }

        return app(ServerBulkSiteActions::class)->previewSelected($this->server, $this->selectedSiteIds);
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

        if ($this->siteQuotaReached($org)) {
            return null;
        }

        // PHP version is intentionally not collected in the modal: the
        // server's preselected default is used at create time and the
        // runtime detector overrides it from the repo (composer.json
        // platform / .tool-versions / etc.) on first clone.
        $defaultPhpVersion = $this->form->type === 'php' && $this->form->php_version !== ''
            ? $this->form->php_version
            : null;

        // Resolve uniqueness BEFORE the insert. The Site model's
        // ensureUniqueSlug() bumps a duplicate slug, but it has to run
        // pre-save — calling it after Site::create() insert (which is
        // what we used to do) trips the (server_id, slug) unique
        // constraint and aborts the request when two sites share a name.
        $baseSlug = Str::slug($this->form->name) ?: 'site';
        $slug = $baseSlug;
        $i = 1;
        while (Site::query()->where('server_id', $this->server->id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }

        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'deploy_script_id' => $org?->default_site_script_id,
            'name' => $this->form->name,
            'slug' => $slug,
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
            'bulkActionsEnabled' => Feature::active('workspace.bulk_site_actions'),
        ]);
    }
}
