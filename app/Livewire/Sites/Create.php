<?php

namespace App\Livewire\Sites;

use App\Modules\Launch\Jobs\FinalizeContainerCloudLaunchJob;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\EnforcesSiteQuota;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Livewire\Forms\SiteCreateForm;
use App\Livewire\Sites\Concerns\ManagesSiteCreateContainer;
use App\Livewire\Sites\Concerns\ManagesSiteCreateDetection;
use App\Livewire\Sites\Concerns\ManagesSiteCreateFormFields;
use App\Livewire\Sites\Concerns\ManagesSiteCreateFunctions;
use App\Livewire\Sites\Concerns\ManagesSiteCreateScaffold;
use App\Livewire\Sites\Concerns\ManagesSiteCreateStore;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Modules\Deploy\Services\LocalRepositoryInspector;
use App\Services\Servers\ServerPhpManager;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Support\HostnameValidator;
use App\Support\Sites\SiteCreateAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    use DetectsRepositoryRuntime;
    use EnforcesSiteQuota;
    use ManagesSiteCreateContainer;
    use ManagesSiteCreateDetection;
    use ManagesSiteCreateFormFields;
    use ManagesSiteCreateFunctions;
    use ManagesSiteCreateScaffold;
    use ManagesSiteCreateStore;
    use RefreshesLinkedSourceControlAccounts;

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

    /**
     * Suggested non-web processes carried forward from the last detection
     * run. The Site::created hook already creates a `web` SiteProcess
     * (with command=null); after store() persists the site, we create
     * one row per entry here so workers/schedulers/etc. land alongside.
     *
     * The {@see DetectsRepositoryRuntime} concern owns `$detectedPlan` and
     * `$runtimeOverridesTouched`; this list is populated from the plan by
     * {@see applyDetectedRuntimePrefills()}.
     *
     * @var list<array{type: string, name: string, command: string, reason: string}>
     */
    public array $detectedProcesses = [];

    /**
     * Surfaces the result of the most recent "install runtime on server"
     * click for inline UI feedback. Empty until the user invokes
     * {@see installDetectedRuntimeOnServer}.
     *
     * @var array<string, mixed>
     */
    public array $runtimeInstallResult = [];

    /**
     * Database engines installed on the target server, formatted for the
     * site-create form's engine picker. Each entry is `{id, label}`.
     * Picker is surfaced in the view only when this list has more than
     * one entry — single-engine servers don't need to ask.
     *
     * @var list<array{id: string, label: string}>
     */
    public array $availableDatabaseEngines = [];

    /**
     * Container-mode state — populated only when the target server's host_kind
     * is docker or kubernetes. The container path renders a wholly different
     * form (repo URL + branch + subdir + namespace) instead of the VM-shaped
     * fields, and submits via {@see storeContainer()} which dispatches the
     * polling job FinalizeContainerCloudLaunchJob.
     */
    public string $container_repo_source = 'manual';

    public string $container_repository_url = '';

    public string $container_repository_branch = 'main';

    public string $container_repository_subdirectory = '';

    public string $container_source_control_account_id = '';

    public string $container_repository_selection = '';

    public string $container_kubernetes_namespace = '';

    /**
     * Most recent inspection payload (LocalRepositoryInspector output) for the
     * container path. Empty until the user types a repo URL and the inspector
     * fires on field-blur.
     *
     * @var array<string, mixed>
     */
    public array $container_inspection = [];

    public bool $container_has_inspection = false;

    /**
     * Linked source-control accounts for the container path's repo picker —
     * separate from $linkedSourceControlAccounts above which is wired to the
     * functions/serverless flow.
     *
     * @var list<array{id: string, provider: string, label: string}>
     */
    public array $containerLinkedSourceControlAccounts = [];

    /**
     * Repositories surfaced from the picked source-control account.
     *
     * @var list<array{label: string, url: string, branch: string}>
     */
    public array $containerAvailableRepositories = [];

    /** Non-empty when mount determined the user cannot create a site on this server. */
    public string $siteCreateBlockedReason = '';

    public function mount(
        Server $server,
        ServerPhpManager $phpManager,
        SourceControlRepositoryBrowser $repositoryBrowser,
    ): void {
        $this->authorize('view', $server);

        $this->server = $server;
        $this->siteCreateBlockedReason = SiteCreateAccess::blockedReason($server);

        if ($this->siteCreateBlockedReason !== '') {
            return;
        }
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

        $deployStack = request()->query('deploy_stack');
        if (
            is_string($deployStack)
            && $deployStack === 'docker'
            && $server->dockerEnginePresent()
            && ! $server->isDockerHost()
            && ! $server->isKubernetesCluster()
        ) {
            $this->form->deploy_stack = 'docker';
        }

        // Build the list of database engines the user can pick from. The
        // default ServerDatabaseEngine row pre-selects in the picker; the
        // form->database_engine column override only applies when the
        // user explicitly chooses a different engine.
        $engines = $server->databaseEngines()->orderBy('engine')->get();
        $this->availableDatabaseEngines = $engines->map(fn ($e) => [
            'id' => (string) $e->engine,
            'label' => trim((string) $e->engine.' '.($e->version ?? '')),
        ])->values()->all();
        $defaultEngine = $engines->firstWhere('is_default', true);
        if ($defaultEngine !== null && $this->form->database_engine === '') {
            $this->form->database_engine = (string) $defaultEngine->engine;
        }

        // Container hosts (docker / kubernetes) take a wholly different form;
        // pre-load the OSS preset & source-control state so the container-mode
        // partial renders without a second round-trip.
        if ($this->isContainerMode()) {
            $this->initializeContainerMode($repositoryBrowser);
        }
    }

    /**
     * Copy the detected plan onto the form. Suggested non-web processes are
     * stashed in {@see $detectedProcesses} for {@see store()} to materialize
     * after the Site row is created; runtime / version / build / start are
     * pre-filled only when the user hasn't manually edited them.
     */
    protected function applyDetectedRuntimePrefills(): void
    {
        $plan = $this->detectedPlan;

        $this->detectedProcesses = is_array($plan['processes'] ?? null)
            ? array_values($plan['processes'])
            : [];

        $runtime = (string) ($plan['runtime'] ?? '');
        if ($runtime === '' || $this->runtimeOverridesTouched) {
            return;
        }

        $this->form->runtime = $runtime;
        $this->form->runtime_version = (string) ($plan['version'] ?? '');
        $this->form->build_command = (string) ($plan['build_command'] ?? '');
        $this->form->start_command = (string) ($plan['start_command'] ?? '');
        // Sync the legacy `type` enum + php_version + app_port so the
        // existing UI sections (which still bind on those) reflect the
        // detected runtime instead of staying on the previous default.
        $this->form->type = $this->mapRuntimeToLegacyType($runtime);
        $this->form->applyPathDefaults();
        if ($runtime === 'php' && ! empty($plan['version'])) {
            $this->form->php_version = (string) $plan['version'];
        }
        if ($runtime === 'node' && ! empty($plan['app_port'])) {
            $this->form->app_port = (int) $plan['app_port'];
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->loadCount('sites');

        return view('livewire.sites.create', [
            'phpVersions' => $this->phpVersions,
            'isContainerMode' => $this->isContainerMode(),
            'containerOssPresets' => $this->isContainerMode() ? $this->containerOssPresets() : [],
            'usesChooseAppBareCreate' => $this->usesChooseAppBareCreate(),
            'dockerDeployRequestedButMissing' => $this->dockerDeployRequestedButMissing(),
        ]);
    }

    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        $this->loadFunctionsSourceControlState(app(SourceControlRepositoryBrowser::class));

        if ($this->isContainerMode()) {
            $this->refreshContainerRepositories(app(SourceControlRepositoryBrowser::class));
        }
    }
}
