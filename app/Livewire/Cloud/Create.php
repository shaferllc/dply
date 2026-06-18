<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Modules\Cloud\Actions\CreateCloudSite;
use App\Modules\Cloud\Actions\CreateCloudSiteFromSource;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Cloud\Concerns\ManagesCloudCostBackend;
use App\Livewire\Cloud\Concerns\ManagesCloudRepository;
use App\Livewire\Cloud\Concerns\ManagesCloudResources;
use App\Livewire\Cloud\Concerns\ValidatesCloudCreateForm;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Models\CloudBucket;
use App\Models\CloudDatabase;
use App\Models\CloudDeployTask;
use App\Models\CloudWorker;
use App\Models\ProviderCredential;
use App\Modules\Billing\Services\ManagedProductCostEstimator;
use App\Modules\Cloud\Backends\AwsAppRunnerBackend;
use App\Modules\Cloud\Backends\CloudRouter;
use App\Modules\Cloud\Backends\DigitalOceanAppPlatformBackend;
use App\Modules\Cloud\Services\DigitalOceanAppPlatformService;
use App\Modules\SourceControl\Services\DefaultBranchResolver;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
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
    use ManagesCloudCostBackend;
    use ManagesCloudRepository;
    use ManagesCloudResources;
    use RefreshesLinkedSourceControlAccounts;
    use ValidatesCloudCreateForm;

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

    /**
     * Multi-database support. Each entry is one CloudDatabase the user is
     * attaching to (mode='attach') or creating alongside the app
     * (mode='create'). Empty array = no databases. Per-entry env_prefix
     * keeps env-var injection from colliding when the same engine appears
     * twice (e.g., "DB" + "DB_ANALYTICS" for two Postgres clusters).
     *
     * @var list<array{
     *     _id: string,
     *     mode: 'attach'|'create',
     *     cloud_database_id?: string,
     *     name: string,
     *     engine: 'postgres'|'mysql'|'redis',
     *     version: string,
     *     size: string,
     *     env_prefix: string,
     * }>
     */
    public array $databases = [];

    /**
     * Object-storage buckets to provision + attach. Same per-entry
     * env_prefix story as databases — multiple buckets per app means
     * S3, S3_UPLOADS, S3_BACKUPS, etc.
     *
     * @var list<array{
     *     _id: string,
     *     name: string,
     *     backend: string,
     *     region: string,
     *     env_prefix: string,
     * }>
     */
    public array $buckets = [];

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


    public function deploy(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

        if (! $org->canCreateSite()) {
            $this->toastError($org->siteLimitMessage());

            return;
        }

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
            'resourceEstimate' => $this->dplyResourceEstimateUsd(),
            'attachableDatabases' => $databases,
            'backendSupportsWorkers' => $this->backend !== 'aws_app_runner',
            'backendSupportsDeployTasks' => $this->backend !== 'aws_app_runner',
        ])->layout('layouts.app');
    }
}
