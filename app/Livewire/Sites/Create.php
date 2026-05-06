<?php

namespace App\Livewire\Sites;

use App\Actions\Servers\InstallRuntimeOnServer;
use App\Enums\ServerProvider;
use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RunLaravelScaffoldJob;
use App\Jobs\RunWordPressScaffoldJob;
use App\Livewire\Forms\SiteCreateForm;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\SiteDomain;
use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeAwareDeployStepDefaults;
use App\Services\Deploy\RuntimeDetection\GitCloneException;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePlan;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePreview;
use App\Services\Deploy\ServerlessRepositoryCheckout;
use App\Services\Deploy\ServerlessRuntimeDetector;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\InternalPortAllocator;
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

    /**
     * Surfaces the merged manifest+detection plan for the URL-first flow.
     * Empty array when no detection has run; an associative array of plan
     * fields (runtime, version, framework, build_command, start_command,
     * app_port, confidence, sources, processes, reasons, warnings,
     * has_manifest, error?) once it has.
     *
     * Mirrors the JSON shape produced by the dply:detect-runtime CLI so
     * the Blade panel can render the same structure the CLI prints.
     *
     * @var array<string, mixed>
     */
    public array $detectedPlan = [];

    /**
     * Suggested non-web processes carried forward from the last detection
     * run. The Site::created hook already creates a `web` SiteProcess
     * (with command=null); after store() persists the site, we create
     * one row per entry here so workers/schedulers/etc. land alongside.
     *
     * @var list<array{type: string, name: string, command: string, reason: string}>
     */
    public array $detectedProcesses = [];

    /**
     * Suppress detection-driven form pre-fills when the user has manually
     * edited any of the managed fields (runtime / runtime_version /
     * build_command / start_command). Mirrors the
     * {@see $functionsOverridesTouched} pattern so a re-detect doesn't
     * stomp manual edits.
     */
    public bool $runtimeOverridesTouched = false;

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
    }

    public function updatedFormType(string $value): void
    {
        $this->form->applyDefaultsForType($value);
    }

    /**
     * Switch the wizard to "Import an existing repo" mode (default).
     */
    public function chooseImportMode(): void
    {
        $this->form->mode = 'import';
        $this->form->scaffold_framework = '';
        $this->form->scaffold_admin_email = '';
    }

    /**
     * Switch the wizard to "Scaffold a new app" mode (Q3 branch).
     * Hides the import form; reveals the tile picker + admin email field.
     */
    public function chooseScaffoldMode(): void
    {
        $this->form->mode = 'scaffold';
    }

    /**
     * Pick a scaffold tile (laravel | wordpress).
     */
    public function chooseScaffoldFramework(string $framework): void
    {
        if (! in_array($framework, ['laravel', 'wordpress'], true)) {
            return;
        }
        $this->form->scaffold_framework = $framework;
    }

    /**
     * Submit handler for scaffold mode.
     *
     * Validates the three scaffold fields (slug + framework + admin email),
     * verifies the feature flag, creates a Site row in STATUS_SCAFFOLDING,
     * then redirects to the (still-WIP) scaffold journey.
     *
     * Pipeline execution lands in PR 5 (Laravel) / PR 6 (WordPress);
     * for now this only persists the Site row + flashes a placeholder
     * message so the wizard surface can be exercised end-to-end.
     */
    public function storeScaffold(): mixed
    {
        $this->authorize('update', $this->server);
        $this->authorize('create', Site::class);

        if (! config('dply.scaffold_v1_enabled')) {
            $this->addError('form.mode', __('Scaffolding is not enabled on this dply install yet.'));

            return null;
        }

        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);
        abort_if($this->server->organization_id !== $org->id, 403);

        // Database-engine compat per Q5: WordPress requires MySQL/MariaDB.
        // We don't auto-block here because the server's engine list may
        // include MariaDB even on a Postgres-default server; the pipeline
        // (PR 6) will pick a compatible engine and surface a wizard error
        // before this if none exists. v1 keeps the gate light at submit
        // time and defers strict checks to the journey's preflight step.

        $this->form->validate([
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', 'in:scaffold'],
            'scaffold_framework' => ['required', 'in:laravel,wordpress'],
            'scaffold_admin_email' => ['required', 'email', 'max:255'],
            'primary_hostname' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'scaffold_framework' => __('starter template'),
            'scaffold_admin_email' => __('admin email'),
        ]);

        $slug = Str::slug($this->form->name) ?: 'site';

        $site = Site::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'organization_id' => $this->server->organization_id,
            'name' => $this->form->name,
            'slug' => $slug,
            // Both Laravel and WordPress are PHP-shaped sites. Locking
            // type=php keeps existing site listings + filters consistent;
            // the framework-specific tabs render off meta.scaffold.framework.
            'type' => SiteType::Php,
            'runtime' => 'php',
            'status' => Site::STATUS_SCAFFOLDING,
            'meta' => [
                'scaffold' => [
                    'framework' => $this->form->scaffold_framework,
                    'admin_email' => $this->form->scaffold_admin_email,
                    'requested_hostname' => trim($this->form->primary_hostname) !== ''
                        ? trim($this->form->primary_hostname)
                        : null,
                    'requested_at' => now()->toISOString(),
                    'requested_by_user_id' => auth()->id(),
                ],
            ],
        ]);

        // Dispatch the framework-specific pipeline. The Site is already
        // in STATUS_SCAFFOLDING; the worker walks it through the steps
        // recorded under meta.scaffold.steps[] for the journey UI (PR 7).
        if ($this->form->scaffold_framework === 'laravel') {
            RunLaravelScaffoldJob::dispatch($site->id);
            session()->flash('info', __('Laravel site queued for scaffolding. The pipeline runs in the background.'));
        } else {
            RunWordPressScaffoldJob::dispatch($site->id);
            session()->flash('info', __('WordPress site queued for scaffolding. The pipeline runs in the background.'));
        }

        return $this->redirect(route('sites.scaffold-journey', [
            'server' => $this->server,
            'site' => $site,
        ]), navigate: true);
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

    public function updatedFormRuntime(): void
    {
        $this->runtimeOverridesTouched = true;
    }

    public function updatedFormRuntimeVersion(): void
    {
        $this->runtimeOverridesTouched = true;
    }

    public function updatedFormBuildCommand(): void
    {
        $this->runtimeOverridesTouched = true;
    }

    public function updatedFormStartCommand(): void
    {
        $this->runtimeOverridesTouched = true;
    }

    /**
     * Run URL-first runtime detection against the form's git URL + branch.
     *
     * Pulls the merged dply.yaml + detector plan via {@see RepositoryRuntimePreview}
     * and pre-fills runtime / runtime_version / build_command / start_command on
     * the form when the user hasn't manually edited any of those fields.
     * Suggested non-web processes are stashed in {@see $detectedProcesses} for
     * {@see store()} to materialize after the Site row is created.
     *
     * Errors (clone failures, missing branch, etc.) land in
     * `$detectedPlan['error']` so the Blade panel can render them inline
     * without aborting the form.
     */
    public function detectFromRepository(RepositoryRuntimePreview $preview): void
    {
        $url = trim($this->form->git_repository_url);
        $branch = trim($this->form->git_branch) !== '' ? trim($this->form->git_branch) : 'main';

        if ($url === '') {
            $this->detectedPlan = [];
            $this->detectedProcesses = [];

            return;
        }

        try {
            $plan = $preview->fromUrl($url, $branch);
        } catch (GitCloneException $e) {
            $this->detectedPlan = [
                'error' => $e->getMessage(),
                'url' => $url,
                'branch' => $branch,
            ];
            $this->detectedProcesses = [];

            return;
        }

        if ($plan === null) {
            $this->detectedPlan = [
                'url' => $url,
                'branch' => $branch,
                'no_match' => true,
            ];
            $this->detectedProcesses = [];

            return;
        }

        $this->detectedPlan = $this->planToArray($plan, $url, $branch);
        $this->detectedProcesses = array_map(
            fn ($p) => [
                'type' => $p->type,
                'name' => $p->name,
                'command' => $p->command,
                'reason' => $p->reason,
            ],
            $plan->processes,
        );

        if (! $this->runtimeOverridesTouched) {
            $this->form->runtime = $plan->runtime;
            $this->form->runtime_version = $plan->version ?? '';
            $this->form->build_command = $plan->buildCommand ?? '';
            $this->form->start_command = $plan->startCommand ?? '';
            // Sync the legacy `type` enum + php_version + app_port so the
            // existing UI sections (which still bind on those) reflect the
            // detected runtime instead of staying on the previous default.
            $this->form->type = $this->mapRuntimeToLegacyType($plan->runtime);
            $this->form->applyPathDefaults();
            if ($plan->runtime === 'php' && $plan->version !== null) {
                $this->form->php_version = $plan->version;
            }
            if ($plan->runtime === 'node' && $plan->appPort !== null) {
                $this->form->app_port = $plan->appPort;
            }
        }
    }

    /**
     * Resolve the value to write to Site.database_engine. Returns null
     * (use server default at read time) when the user accepted the
     * server's default; returns the picked engine string when they
     * chose a non-default one.
     */
    private function resolveDatabaseEngineOverride(): ?string
    {
        $picked = trim($this->form->database_engine);
        if ($picked === '') {
            return null;
        }

        $default = $this->server->defaultDatabaseEngine();
        if ($default !== null && $default->engine === $picked) {
            return null;
        }

        return $picked;
    }

    /**
     * Whether the inline "Install <runtime> on this server" affordance
     * should appear in the detection panel. True when:
     *   - detection has produced a runtime,
     *   - that runtime is one mise can manage (not PHP, not static), and
     *   - the server hasn't already pinned it via meta.runtime_defaults.
     *
     * Exposed as a Livewire-magic computed property so the Blade panel
     * can call `$this->detectedRuntimeNeedsInstall` without an in-template
     *
     * @php block (Blade's compileString has trouble parsing block-form
     * @php with array literals containing 'php'/'static' string keys —
     * Livewire-side computation sidesteps that entirely).
     */
    public function getDetectedRuntimeNeedsInstallProperty(): bool
    {
        $runtime = (string) ($this->detectedPlan['runtime'] ?? '');
        if ($runtime === '' || in_array($runtime, ['php', 'static'], true)) {
            return false;
        }

        return ! $this->server->hasRuntimeInstalled($runtime);
    }

    /**
     * Trigger runtime installation on the current server using the
     * detected runtime + version. Used by the inline "Install <runtime>
     * on this server" affordance the panel surfaces when the detected
     * runtime is missing from `server->installedRuntimeKeys()`.
     */
    public function installDetectedRuntimeOnServer(InstallRuntimeOnServer $action): void
    {
        $this->authorize('update', $this->server);

        $runtime = (string) ($this->detectedPlan['runtime'] ?? '');
        $version = (string) ($this->detectedPlan['version'] ?? '');

        if ($runtime === '' || $version === '') {
            $this->runtimeInstallResult = [
                'ok' => false,
                'message' => __('Run detection first so we have a runtime + version to install.'),
            ];

            return;
        }

        try {
            $result = $action->execute($this->server, $runtime, $version);
        } catch (\Throwable $e) {
            $this->runtimeInstallResult = [
                'ok' => false,
                'runtime' => $runtime,
                'version' => $version,
                'message' => $e->getMessage(),
            ];

            return;
        }

        $this->server->refresh();

        $this->runtimeInstallResult = [
            'ok' => $result['installed'],
            'runtime' => $result['runtime'],
            'version' => $result['version'],
            'message' => $result['installed']
                ? __('Installed :runtime :version on this server.', ['runtime' => $runtime, 'version' => $version])
                : __('Skipped — runtime not eligible for mise-managed install.'),
        ];
    }

    /**
     * Pure-PHP renderer for the plan into the array shape the Blade panel
     * and any tests assert against.
     *
     * @return array<string, mixed>
     */
    private function planToArray(RepositoryRuntimePlan $plan, string $url, string $branch): array
    {
        return [
            'url' => $url,
            'branch' => $branch,
            'runtime' => $plan->runtime,
            'version' => $plan->version,
            'framework' => $plan->framework,
            'build_command' => $plan->buildCommand,
            'start_command' => $plan->startCommand,
            'app_port' => $plan->appPort,
            'confidence' => $plan->confidence,
            'sources' => $plan->sources,
            'reasons' => $plan->reasons,
            'warnings' => $plan->warnings,
            'has_manifest' => $plan->hasManifest(),
            'processes' => array_map(
                fn ($p) => [
                    'type' => $p->type,
                    'name' => $p->name,
                    'command' => $p->command,
                    'reason' => $p->reason,
                ],
                $plan->processes,
            ),
        ];
    }

    /**
     * Map a detected runtime onto the existing {@see SiteType} enum. The
     * enum still drives a lot of legacy UI/provisioner branches; we'll
     * collapse it into the new `runtime` column in a follow-up. For
     * runtimes the enum doesn't yet model (python/ruby/go) we fall back
     * to "node" as the closest approximation — the new `runtime` column
     * carries the truth, and downstream provisioners read that.
     */
    private function mapRuntimeToLegacyType(string $runtime): string
    {
        return match ($runtime) {
            'php' => 'php',
            'static' => 'static',
            default => 'node',
        };
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

        // The new runtime-agnostic fields drive the URL-first flow. When
        // they aren't populated (legacy flow, or non-VM hosts) we fall
        // back to the existing type-based logic so behavior is unchanged.
        $effectiveRuntime = $this->form->runtime !== ''
            ? $this->form->runtime
            : $this->form->type;
        $allocatesInternalPort = ! $functionsHost
            && ! $containerHost
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
        $defaults = app(RuntimeAwareDeployStepDefaults::class)
            ->defaultsFor($site->runtime, $effectiveFramework !== '' ? $effectiveFramework : null);
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
