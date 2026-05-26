<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Actions\Cloud\CreateCloudSite;
use App\Actions\Cloud\CreateCloudSiteFromSource;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Models\CloudDatabase;
use App\Models\CloudDeployTask;
use App\Models\CloudWorker;
use App\Services\DigitalOceanAppPlatformService;
use App\Models\ProviderCredential;
use App\Services\Billing\ManagedProductCostEstimator;
use App\Services\Cloud\AwsAppRunnerBackend;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * App create flow for the dply cloud platform — the user-facing
 * surface is a Laravel-Cloud-shaped "Deploy an app" experience.
 * The container backend (DO App Platform / AWS App Runner) is an
 * implementation detail picked silently by CloudRouter::pickAutoBackend.
 */
class Create extends Component
{
    use DetectsRepositoryRuntime;
    use DispatchesToastNotifications;
    use RefreshesLinkedSourceControlAccounts;

    /**
     * Backend slug that will actually run the app — resolved at mount()
     * from the org's connected credentials. Not exposed in the form; the
     * value is needed internally so `regions` and validation work.
     */
    public string $backend = 'auto';

    /**
     * 'image' = pre-built image (the existing flow). 'source' = give
     * us a GitHub repo and the backend handles build + deploy +
     * auto-redeploy on push (the Vercel-shape flow).
     */
    #[Url]
    public string $mode = 'image';

    public string $name = '';

    public string $image = '';

    /**
     * 'manual' = type owner/name. 'connected' = pick from a GitHub
     * account already linked to the user's profile via OAuth.
     */
    public string $repo_source = 'manual';

    public string $source_control_account_id = '';

    public string $repository_selection = '';

    public string $repo = '';

    public string $branch = 'main';

    public string $dockerfile_path = '';

    public bool $deploy_on_push = true;

    /**
     * @var list<array{id: string, label: string}>
     */
    public array $linkedSourceControlAccounts = [];

    /**
     * @var list<array{url: string, name: string, branch: string}>
     */
    public array $availableRepositories = [];

    public int $port = 8080;

    /**
     * Suppress detection-driven port pre-fill once the user has typed their
     * own HTTP port, so a re-detect doesn't stomp it.
     */
    public bool $portOverridesTouched = false;

    public int $instances = 1;

    /** Compute tier — small | medium | large | xlarge. */
    public string $size_tier = 'small';

    public string $region = '';

    public string $env_file_content = '';

    /** @var list<array{type: string, name: string, command: string, size: string, instance_count: int}> */
    public array $workers = [];

    /**
     * First-class "Run migrations on deploy" toggle. When on, a
     * cloud_deploy_tasks row with name='migrate' + trigger='pre_deploy'
     * is created with the command below.
     */
    public bool $migrations_enabled = false;

    public string $migrations_command = '';

    /**
     * Extras repeater for additional deploy tasks (post_deploy hooks,
     * failed_deploy cleanup, manual ad-hoc commands, plus pre_deploy
     * tasks beyond the migration default).
     *
     * @var list<array{trigger: string, name: string, command: string, size: string}>
     */
    public array $deploy_tasks = [];

    public bool $autoscaling_enabled = false;

    public int $autoscaling_min = 1;

    public int $autoscaling_max = 3;

    public int $autoscaling_cpu_percent = 75;

    public bool $health_check_enabled = false;

    public string $health_check_path = '/healthz';

    public int $health_check_period_seconds = 30;

    public int $health_check_timeout_seconds = 5;

    public int $health_check_failure_threshold = 3;

    public string $database_mode = 'none';

    public ?string $database_id = null;

    public string $new_database_engine = 'postgres';

    public string $new_database_size = 'small';

    public string $new_database_name = '';

    /** @var list<string> */
    public array $domains = [];

    public string $new_domain = '';

    /**
     * Per-site overrides for the four Cloud alerts. Defaults match
     * CloudAlerts::defaultConfig() — all on, sensible thresholds —
     * so users who never open the section still get production-
     * credible alerting. Users tune in the form to disable a specific
     * rule or bump a threshold.
     */
    public bool $alert_deployment_failed_enabled = true;

    public bool $alert_restart_count_enabled = true;

    public int $alert_restart_count_value = 3;

    public bool $alert_cpu_enabled = true;

    public int $alert_cpu_value = 80;

    public bool $alert_mem_enabled = true;

    public int $alert_mem_value = 80;

    /**
     * When true, this site's alerts route to its own Slack/emails
     * instead of the org-level defaults. The override fields below
     * are only persisted when this is on.
     */
    public bool $alert_destinations_override_enabled = false;

    public string $alert_destinations_override_slack = '';

    public string $alert_destinations_override_emails = '';

    /**
     * Latest DO /apps/propose result. `value` is the estimated monthly
     * cost in USD; `error` is set when propose 4xxs (bad spec, invalid
     * combo, etc.). Surfaces as inline preview + as a submit-time gate.
     *
     * @var array{value: ?float, error: ?string}
     */
    public array $costPreview = ['value' => null, 'error' => null];

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size_tier.ends_with' => __('CPU autoscaling needs a Pro-tier size. Pick one of the Pro sizes above, or disable autoscaling.'),
        ];
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'instances' => ['required', 'integer', 'min:1', 'max:50'],
            'size_tier' => ['required', 'in:small,medium,large,xlarge,small-pro,medium-pro,large-pro,xlarge-pro'],
            'region' => ['required', 'string', 'max:50'],
            'backend' => ['required', 'in:auto,digitalocean_app_platform,aws_app_runner'],
            'mode' => ['required', 'in:image,source'],
            'env_file_content' => ['nullable', 'string', 'max:20000'],
        ];

        if ($this->mode === 'source') {
            $rules['repo'] = ['required', 'string', 'max:200'];
            $rules['branch'] = ['required', 'string', 'max:120'];
            $rules['dockerfile_path'] = ['nullable', 'string', 'max:200'];
        } else {
            $rules['image'] = ['required', 'string', 'max:500'];
        }

        if ($this->autoscaling_enabled) {
            $rules['autoscaling_min'] = ['required', 'integer', 'min:1', 'max:50'];
            $rules['autoscaling_max'] = ['required', 'integer', 'min:1', 'max:50', 'gte:autoscaling_min'];
            $rules['autoscaling_cpu_percent'] = ['required', 'integer', 'min:1', 'max:100'];
            // DO App Platform restricts CPU autoscaling to Professional
            // tier instances. Block the bad combo here so we don't ship
            // an unservable spec and bounce off DO's spec validator.
            $rules['size_tier'][] = 'ends_with:-pro';
        }

        if ($this->health_check_enabled) {
            $rules['health_check_path'] = ['required', 'string', 'regex:#^/#'];
            $rules['health_check_period_seconds'] = ['required', 'integer', 'min:1'];
            $rules['health_check_timeout_seconds'] = ['required', 'integer', 'min:1'];
            $rules['health_check_failure_threshold'] = ['required', 'integer', 'min:1'];
        }

        if ($this->database_mode === 'attach') {
            $rules['database_id'] = ['required', 'string'];
        }

        if ($this->database_mode === 'create') {
            $rules['new_database_name'] = ['required', 'string', 'min:3', 'max:60'];
            $rules['new_database_engine'] = ['required', 'in:postgres,mysql,redis'];
            $rules['new_database_size'] = ['required', 'in:small,medium,large'];
        }

        if ($this->workers !== []) {
            // Workers run inside the same image as the web service. An
            // empty command boots a container that exits immediately and
            // DO marks the whole deploy as "exceeded resource limits or
            // app misbehaving" — surface the gap at submit time instead.
            $rules['workers.*.command'] = ['required', 'string', 'max:500'];
            $rules['workers.*.name'] = ['required', 'string', 'max:60'];
        }

        if ($this->migrations_enabled) {
            $rules['migrations_command'] = ['required', 'string', 'max:500'];
        }

        if ($this->deploy_tasks !== []) {
            $triggers = implode(',', array_keys(CloudDeployTask::DO_KIND_MAP));
            $rules['deploy_tasks.*.trigger'] = ['required', 'string', 'in:'.$triggers];
            $rules['deploy_tasks.*.name'] = ['required', 'string', 'max:60'];
            $rules['deploy_tasks.*.command'] = ['required', 'string', 'max:500'];
            $rules['deploy_tasks.*.size'] = ['nullable', 'string'];
        }

        if ($this->alert_restart_count_enabled) {
            $rules['alert_restart_count_value'] = ['required', 'integer', 'min:1', 'max:100'];
        }
        if ($this->alert_cpu_enabled) {
            $rules['alert_cpu_value'] = ['required', 'integer', 'min:1', 'max:100'];
        }
        if ($this->alert_mem_enabled) {
            $rules['alert_mem_value'] = ['required', 'integer', 'min:1', 'max:100'];
        }
        if ($this->alert_destinations_override_enabled) {
            $rules['alert_destinations_override_slack'] = ['nullable', 'url', 'starts_with:https://', 'max:500'];
            $rules['alert_destinations_override_emails'] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    public function addDomain(): void
    {
        $hostname = strtolower(trim($this->new_domain));
        if ($hostname === '') {
            $this->toastError(__('Hostname is required.'));

            return;
        }
        if (! preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $hostname)) {
            $this->toastError(__('That doesn\'t look like a valid hostname.'));

            return;
        }
        if (in_array($hostname, $this->domains, true)) {
            $this->new_domain = '';

            return;
        }
        $this->domains[] = $hostname;
        $this->new_domain = '';
    }

    public function removeDomain(int $index): void
    {
        if (! isset($this->domains[$index])) {
            return;
        }
        array_splice($this->domains, $index, 1);
        $this->domains = array_values($this->domains);
    }

    public function addWorker(string $type = CloudWorker::TYPE_WORKER): void
    {
        if ($type === CloudWorker::TYPE_SCHEDULER && $this->hasScheduler()) {
            $this->toastError(__('Only one scheduler is allowed per site.'));

            return;
        }

        // Source mode = dply builds with a buildpack and Laravel is the
        // default story, so pre-fill the artisan command. Image mode is
        // BYO container — we don't know what's installed, so leave the
        // command blank and force a deliberate value before submit. The
        // form's `required` rule on workers.*.command then surfaces the
        // gap cleanly instead of letting users ship a Laravel command to
        // a Postgres/nginx/whatever container that doesn't have `php`.
        $isSourceMode = $this->mode === 'source';
        $command = $type === CloudWorker::TYPE_SCHEDULER
            ? ($isSourceMode ? CloudWorker::SCHEDULER_COMMAND : '')
            : ($isSourceMode ? CloudWorker::DEFAULT_WORKER_COMMAND : '');

        $this->workers[] = [
            'type' => $type,
            'name' => $type === CloudWorker::TYPE_SCHEDULER ? 'scheduler' : 'worker-'.(count($this->workers) + 1),
            'command' => $command,
            'size' => 'small',
            'instance_count' => 1,
        ];
    }

    public function removeWorker(int $index): void
    {
        if (! isset($this->workers[$index])) {
            return;
        }
        array_splice($this->workers, $index, 1);
        $this->workers = array_values($this->workers);
    }

    public function hasScheduler(): bool
    {
        foreach ($this->workers as $worker) {
            if (($worker['type'] ?? null) === CloudWorker::TYPE_SCHEDULER) {
                return true;
            }
        }

        return false;
    }

    public function addDeployTask(string $trigger = CloudDeployTask::TRIGGER_PRE_DEPLOY): void
    {
        if (! in_array($trigger, array_keys(CloudDeployTask::DO_KIND_MAP), true)) {
            $trigger = CloudDeployTask::TRIGGER_PRE_DEPLOY;
        }

        // Empty command default for image mode (we don't know what's
        // in the user's image). In source mode we still leave it blank
        // — the first-class "Run migrations" field already covers the
        // common case and a blank command nudges deliberate input.
        $this->deploy_tasks[] = [
            'trigger' => $trigger,
            'name' => 'task-'.(count($this->deploy_tasks) + 1),
            'command' => '',
            'size' => 'small',
        ];
    }

    public function removeDeployTask(int $index): void
    {
        if (! isset($this->deploy_tasks[$index])) {
            return;
        }
        array_splice($this->deploy_tasks, $index, 1);
        $this->deploy_tasks = array_values($this->deploy_tasks);
    }

    public function mount(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        abort_unless(Feature::active('surface.cloud'), 404);

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        // Resolve the backend now (silently). The form never exposes this —
        // users describe an app, dply chooses where to run it.
        $picked = CloudRouter::pickAutoBackend($org->id);
        $this->backend = $picked ?? 'digitalocean_app_platform';

        // Default region tied to the picked backend.
        $this->updatedBackend($this->backend);

        // Migrations default: pre-fill the command in source mode (the
        // buildpack guarantees PHP is available) and leave blank in
        // image mode where we can't know what's in the user's image.
        // The checkbox itself starts off; the user opts in.
        if ($this->mode === 'source') {
            $this->migrations_command = CloudDeployTask::DEFAULT_MIGRATE_COMMAND;
        }

        // Pre-populate linked GitHub / GitLab accounts so the source
        // tab can offer a repo dropdown without a round trip.
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());
        if ($this->linkedSourceControlAccounts !== []) {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            $this->loadRepositoriesForSelectedAccount();
            // When at least one account is linked, default the source-mode
            // picker to "connected" so the dropdown is what the user sees
            // first. They can still toggle to manual entry.
            $this->repo_source = 'connected';
        }
    }

    public function updatedRepoSource(string $value): void
    {
        // Switching back to manual entry clears the dropdown selection
        // so the repo / branch fields don't carry over silently.
        if ($value === 'manual') {
            $this->repository_selection = '';
        }
    }

    public function updatedSourceControlAccountId(string $value): void
    {
        $this->source_control_account_id = $value;
        $this->repository_selection = '';
        $this->loadRepositoriesForSelectedAccount();
    }

    public function updatedRepositorySelection(string $value): void
    {
        if ($value === '') {
            return;
        }

        $match = collect($this->availableRepositories)->firstWhere('url', $value);
        if (! is_array($match)) {
            return;
        }

        $this->repo = $this->normalizeRepo((string) $match['url']);
        $this->branch = is_string($match['branch'] ?? null) && $match['branch'] !== ''
            ? (string) $match['branch']
            : 'main';

        // Picking a repo from a connected account is a deliberate choice —
        // detect immediately so the user sees the runtime preview without a
        // separate click. Manual entry uses the explicit Detect button.
        $this->detectFromRepository();
    }

    /**
     * URL-first detection for source mode — clone the repo and surface the
     * detected runtime / framework / port in the shared panel. No-op in
     * image mode (there's no repo to inspect). Non-blocking: a clone failure
     * lands in `$detectedPlan['error']` and never blocks {@see deploy()}.
     */
    public function detectFromRepository(): void
    {
        if ($this->mode !== 'source') {
            return;
        }

        $this->runDetection($this->normalizeToCloneUrl($this->repo), $this->branch);
    }

    public function updatedPort(): void
    {
        $this->portOverridesTouched = true;
    }

    /**
     * Pre-fill the container HTTP port from the detected app port, unless the
     * user has already typed their own.
     */
    protected function applyDetectedRuntimePrefills(): void
    {
        if ($this->portOverridesTouched) {
            return;
        }

        $port = $this->detectedPlan['app_port'] ?? null;
        if (is_int($port) && $port >= 1 && $port <= 65535) {
            $this->port = $port;
        }
    }

    private function loadRepositoriesForSelectedAccount(): void
    {
        if ($this->source_control_account_id === '') {
            $this->availableRepositories = [];

            return;
        }

        $account = auth()->user() !== null
            ? app(GitIdentityResolver::class)->forId(auth()->user(), $this->source_control_account_id)
            : null;
        $this->availableRepositories = $account
            ? app(SourceControlRepositoryBrowser::class)->repositoriesForAccount($account)
            : [];
    }

    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        if ($this->source_control_account_id === '') {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
        }

        $this->loadRepositoriesForSelectedAccount();
        $this->repo_source = 'connected';
    }

    private function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }

        return trim($value, '/');
    }

    public function updatedBackend(string $value): void
    {
        $regions = $this->backendRegions($value);
        if ($regions !== [] && ($this->region === '' || ! in_array($this->region, array_column($regions, 'slug'), true))) {
            $this->region = $regions[0]['slug'];
        }
    }

    /**
     * Live cost preview + spec validation via DO /apps/propose. Called
     * by the form's "Estimate" button and by the deploy() pre-flight
     * gate. Stores the estimate or the error on $costPreview so the
     * blade can show either a price or an inline diagnostic.
     *
     * Only meaningful when the resolved backend is DO App Platform —
     * App Runner doesn't expose a propose endpoint so we no-op for it.
     */
    public function recomputeCostPreview(): void
    {
        if ($this->backend !== 'digitalocean_app_platform') {
            $this->costPreview = ['value' => null, 'error' => null];

            return;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $credential = \App\Models\ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->orderBy('created_at')
            ->first();
        if ($credential === null) {
            $this->costPreview = ['value' => null, 'error' => null];

            return;
        }

        $sizeSlugMap = CloudDeployTask::SIZE_TIERS;
        $sizeSlug = $sizeSlugMap[$this->size_tier] ?? 'basic-xxs';

        $payload = [
            'name' => $this->name,
            'region' => $this->region,
            'size_tier_slug' => $sizeSlug,
            'instances' => $this->instances,
            'port' => $this->port,
            'mode' => $this->mode,
            'image' => $this->image,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'dockerfile_path' => $this->dockerfile_path,
            'autoscaling' => $this->autoscaling_enabled ? [
                'enabled' => true,
                'min_instances' => $this->autoscaling_min,
                'max_instances' => $this->autoscaling_max,
                'cpu_percent' => $this->autoscaling_cpu_percent,
            ] : null,
            'health_check' => $this->health_check_enabled ? [
                'enabled' => true,
                'http_path' => $this->health_check_path,
                'period_seconds' => $this->health_check_period_seconds,
                'timeout_seconds' => $this->health_check_timeout_seconds,
                'failure_threshold' => $this->health_check_failure_threshold,
            ] : null,
        ];

        $spec = \App\Services\Cloud\DigitalOceanAppPlatformBackend::buildProposeSpecFromPayload($payload);

        try {
            $result = (new DigitalOceanAppPlatformService($credential))->proposeApp($spec);
            $this->costPreview = [
                'value' => $result['app_cost'],
                'error' => $result['error'],
            ];
        } catch (\Throwable $e) {
            // Network blip / unexpected response — surface as a soft
            // warning so the user can still submit. Real errors land
            // in `error` from DO's structured response above.
            $this->costPreview = ['value' => null, 'error' => null];
            report($e);
        }
    }

    public function deploy(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

        // Pre-flight via /apps/propose — catches DO spec rejections
        // (autoscaling-on-Basic, bad region taxonomy, missing image
        // creds) BEFORE we create a Site row that just lands in
        // container_failed. Skips silently when there's no DO
        // credential yet (Fake / no-cred dev installs).
        $this->recomputeCostPreview();
        if (is_string($this->costPreview['error'] ?? null) && $this->costPreview['error'] !== '') {
            $this->toastError(__('Spec rejected by cloud provider: :error', ['error' => $this->costPreview['error']]));

            return;
        }

        $extras = $this->extrasPayload();

        try {
            $site = $this->mode === 'source'
                ? (new CreateCloudSiteFromSource)->handle(auth()->user(), $org, [
                    'name' => $this->name,
                    'repo' => $this->repo,
                    'branch' => $this->branch,
                    'dockerfile_path' => $this->dockerfile_path,
                    'deploy_on_push' => $this->deploy_on_push,
                    'port' => $this->port,
                    'instances' => $this->instances,
                    'size_tier' => $this->size_tier,
                    'region' => $this->region,
                    'backend' => $this->backend,
                    'env_file_content' => $this->env_file_content,
                    ...$extras,
                ])
                : (new CreateCloudSite)->handle(auth()->user(), $org, [
                    'name' => $this->name,
                    'image' => $this->image,
                    'port' => $this->port,
                    'instances' => $this->instances,
                    'size_tier' => $this->size_tier,
                    'region' => $this->region,
                    'backend' => $this->backend,
                    'env_file_content' => $this->env_file_content,
                    ...$extras,
                ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('App is provisioning. We\'ll keep this page updated as it comes online.'));
        $this->redirect(route('sites.show', ['server' => $site->server, 'site' => $site]), navigate: true);
    }

    /**
     * @return list<array{slug: string, label: string}>
     */
    private function backendRegions(string $backend): array
    {
        return match ($backend) {
            'digitalocean_app_platform' => DigitalOceanAppPlatformBackend::class === '' ? [] : (new DigitalOceanAppPlatformBackend)->regions(),
            'aws_app_runner' => (new AwsAppRunnerBackend)->regions(),
            default => $this->mergedRegions(),
        };
    }

    /**
     * @return list<array{slug: string, label: string}>
     */
    private function mergedRegions(): array
    {
        $merged = [];
        foreach ((new DigitalOceanAppPlatformBackend)->regions() as $r) {
            $merged[$r['slug']] = ['slug' => $r['slug'], 'label' => 'DO · '.$r['label']];
        }
        foreach ((new AwsAppRunnerBackend)->regions() as $r) {
            $merged[$r['slug']] = ['slug' => $r['slug'], 'label' => 'AWS · '.$r['label']];
        }

        return array_values($merged);
    }

    /**
     * @return array<string, mixed>
     */
    private function extrasPayload(): array
    {
        $extras = [];

        if ($this->workers !== []) {
            $extras['workers'] = array_map(static fn (array $w): array => [
                'type' => (string) ($w['type'] ?? CloudWorker::TYPE_WORKER),
                'name' => (string) ($w['name'] ?? ''),
                'command' => (string) ($w['command'] ?? ''),
                'size' => (string) ($w['size'] ?? 'small'),
                'instance_count' => (int) ($w['instance_count'] ?? 1),
            ], $this->workers);
        }

        $tasksPayload = [];
        if ($this->migrations_enabled && trim($this->migrations_command) !== '') {
            $tasksPayload[] = [
                'trigger' => CloudDeployTask::TRIGGER_PRE_DEPLOY,
                'name' => CloudDeployTask::NAME_MIGRATE,
                'command' => $this->migrations_command,
                'size' => 'small',
            ];
        }
        foreach ($this->deploy_tasks as $task) {
            $command = trim((string) ($task['command'] ?? ''));
            if ($command === '') {
                continue;
            }
            $tasksPayload[] = [
                'trigger' => (string) ($task['trigger'] ?? CloudDeployTask::TRIGGER_PRE_DEPLOY),
                'name' => (string) ($task['name'] ?? ''),
                'command' => $command,
                'size' => (string) ($task['size'] ?? 'small'),
            ];
        }
        if ($tasksPayload !== []) {
            $extras['deploy_tasks'] = $tasksPayload;
        }

        if ($this->autoscaling_enabled) {
            $extras['autoscaling'] = [
                'enabled' => true,
                'min_instances' => $this->autoscaling_min,
                'max_instances' => $this->autoscaling_max,
                'cpu_percent' => $this->autoscaling_cpu_percent,
            ];
        }

        if ($this->health_check_enabled) {
            $extras['health_check'] = [
                'enabled' => true,
                'http_path' => $this->health_check_path,
                'period_seconds' => $this->health_check_period_seconds,
                'timeout_seconds' => $this->health_check_timeout_seconds,
                'failure_threshold' => $this->health_check_failure_threshold,
            ];
        }

        if ($this->database_mode === 'attach' && $this->database_id !== null && $this->database_id !== '') {
            $extras['database'] = [
                'mode' => 'attach',
                'cloud_database_id' => $this->database_id,
            ];
        }

        if ($this->database_mode === 'create') {
            $extras['database'] = [
                'mode' => 'create',
                'name' => $this->new_database_name,
                'engine' => $this->new_database_engine,
                'size' => $this->new_database_size,
                'region' => $this->region,
            ];
        }

        if ($this->domains !== []) {
            $extras['domains'] = $this->domains;
        }

        // Alerts always emitted — defaults match CloudAlerts so the
        // payload is harmless even when the user never opens the
        // section. Per-site override only when explicitly enabled.
        $alerts = [
            'deployment_failed' => ['enabled' => $this->alert_deployment_failed_enabled],
            'restart_count' => ['enabled' => $this->alert_restart_count_enabled, 'value' => $this->alert_restart_count_value, 'window' => 'FIVE_MINUTES'],
            'cpu_utilization' => ['enabled' => $this->alert_cpu_enabled, 'value' => $this->alert_cpu_value, 'window' => 'FIVE_MINUTES'],
            'mem_utilization' => ['enabled' => $this->alert_mem_enabled, 'value' => $this->alert_mem_value, 'window' => 'FIVE_MINUTES'],
        ];
        if ($this->alert_destinations_override_enabled) {
            $emails = array_values(array_filter(array_map(
                'trim',
                preg_split('/[\s,]+/', $this->alert_destinations_override_emails) ?: [],
            )));
            $alerts['destinations_override'] = [
                'slack_webhook_url' => trim($this->alert_destinations_override_slack) ?: null,
                'emails' => $emails,
            ];
        }
        $extras['alerts'] = $alerts;

        return $extras;
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $connected = $org === null ? collect() : ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->whereIn('provider', CloudRouter::credentialProviderKeys())
            ->get(['id', 'provider', 'name', 'credentials']);

        $databases = $org === null ? collect() : CloudDatabase::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', [CloudDatabase::STATUS_ACTIVE, CloudDatabase::STATUS_PROVISIONING])
            ->orderBy('name')
            ->get(['id', 'name', 'engine', 'status']);

        // Source mode on AWS App Runner needs an authorized GitHub
        // connection on the credential. Surface this in the form so
        // we don't let the user submit then fail at provision time.
        $awsCred = $connected->firstWhere('provider', 'aws_app_runner');
        $awsSourceReady = $awsCred !== null
            && is_array($awsCred->credentials)
            && is_string($awsCred->credentials['github_connection_arn'] ?? null)
            && $awsCred->credentials['github_connection_arn'] !== '';

        return view('livewire.cloud.create', [
            'connectedBackends' => $connected,
            'regions' => $this->backendRegions($this->backend),
            'awsSourceReady' => $awsSourceReady,
            'fakeCloudActive' => FakeCloudProvision::enabled(),
            'cloudFee' => app(ManagedProductCostEstimator::class)->cloudFee(),
            'attachableDatabases' => $databases,
            'backendSupportsWorkers' => $this->backend !== 'aws_app_runner',
            'backendSupportsDeployTasks' => $this->backend !== 'aws_app_runner',
        ])->layout('layouts.app');
    }
}
