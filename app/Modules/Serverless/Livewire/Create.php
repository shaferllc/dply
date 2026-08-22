<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Livewire\Concerns\Sites\ConfiguresGitRepository;
use App\Models\ProviderCredential;
use App\Modules\Deploy\Services\ServerlessRuntimeDetector;
use App\Modules\Deploy\Services\ServerlessTargetCapabilityResolver;
use App\Modules\Serverless\Actions\CreateServerlessFunction;
use App\Modules\Serverless\Livewire\Concerns\ManagesServerlessCreateGit;
use App\Modules\Serverless\Services\ServerlessCostEstimator;
use App\Modules\Serverless\Services\ServerlessCreateGate;
use App\Support\SiteCreateBlocker;
use App\Modules\Serverless\Support\ServerlessPlatformContext;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Support\Serverless\ServerlessWorkspaceUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * One-shot "Create a serverless app" flow — the FaaS counterpart to
 * Cloud\Create. Collects a DO credential + region + repo + runtime, hands
 * off to {@see CreateServerlessFunction}, which stands up the host
 * namespace + first function and kicks a deploy.
 */
#[Layout('layouts.app')]
class Create extends Component
{
    use ConfiguresGitRepository;
    use DetectsRepositoryRuntime;
    use DispatchesToastNotifications;
    use ManagesServerlessCreateGit {
        // Shared picker ships empty on* hooks; serverless create owns them
        // (auto-detect + app-name seeding).
        ManagesServerlessCreateGit::onRepositorySelected insteadof ConfiguresGitRepository;
        ManagesServerlessCreateGit::onManualRepoUrlChanged insteadof ConfiguresGitRepository;
    }
    use RefreshesLinkedSourceControlAccounts;

    public string $provider_credential_id = '';

    /**
     * Where the function runs:
     *   managed = dply's own FaaS account (dply pays the provider, bills usage
     *             cost-plus on top of the flat fee);
     *   byo     = the customer's connected DigitalOcean credential.
     */
    public string $delivery_mode = 'byo';

    /**
     * Preselect a DigitalOcean credential. A BYO serverless host with no
     * credential cannot provision its namespace — the deploy dies at
     * `serverless.namespace.no_credential` — so the BYO form must never submit
     * without one. Default to managed when it's available and the org has no
     * DigitalOcean credential of its own.
     */
    public function mount(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        abort_unless(Feature::active('surface.serverless'), 404);

        $this->mounting = true;

        $user = auth()->user();
        $org = $user?->currentOrganization();
        if ($org === null) {
            return;
        }

        // Prefer a credential DigitalOcean still accepts. `value('id')` with
        // no order used to preselect the oldest row — often a revoked token
        // sitting next to a working one — and namespace provision 401'd
        // while the operator pointed at DIGITALOCEAN_TOKEN on the core app.
        $preferred = ProviderCredential::preferredHealthyForOrganization($org->id, 'digitalocean');

        if ($preferred !== null) {
            $this->provider_credential_id = $preferred->id;
        } elseif ($this->managedAvailable()) {
            $this->delivery_mode = 'managed';
        }

        // Shared Git picker — connected accounts first, else paste a URL.
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser($user);
        if ($this->linkedSourceControlAccounts !== []) {
            $this->repo_source = 'provider';
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            $this->refreshRepositories($repositoryBrowser);
        }

        // `?starter=…` — the starter page's demo tiles land here prefilled, so
        // the operator only picks a region/credential before hitting Create.
        // Runs last: the demo prefill deliberately overrides the Git picker
        // defaults set above.
        match ((string) request()->query('starter', '')) {
            'laravel' => $this->loadLaravelDemo(),
            'php' => $this->loadPhpDemo(),
            default => null,
        };
    }

    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        $this->repo_source = 'provider';
        if ($this->source_control_account_id === '') {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
        }
        $this->refreshRepositories(app(SourceControlRepositoryBrowser::class));
    }

    #[On('provider-credential-created')]
    public function applyStoredProviderCredential(?string $provider = null, mixed $credentialId = null): void
    {
        if ($provider !== 'digitalocean' || $credentialId === null || $credentialId === '') {
            return;
        }

        $this->provider_credential_id = (string) $credentialId;
        if ($this->delivery_mode !== 'managed') {
            $this->delivery_mode = 'byo';
        }
    }

    /**
     * True when the dply-managed option is both feature-enabled and the
     * platform namespace is configured to receive deploys.
     */
    public function managedAvailable(): bool
    {
        return Feature::active('surface.serverless_managed')
            && ServerlessPlatformContext::fromConfig()->configured();
    }

    private function selectedCredentialIsUnhealthy(string $organizationId): bool
    {
        $credentialId = trim($this->provider_credential_id);
        if ($credentialId === '') {
            return false;
        }

        $credential = ProviderCredential::query()
            ->where('id', $credentialId)
            ->where('organization_id', $organizationId)
            ->where('provider', 'digitalocean')
            ->first();

        return $credential?->isUnhealthy() ?? false;
    }

    public string $region = 'nyc1';

    public string $name = '';

    /**
     * Runtime selection. Defaults to `auto` — the deploy-time
     * {@see ServerlessRuntimeDetector} picks the runtime
     * from the repository. An explicit value overrides detection.
     */
    public string $runtime = 'auto';

    /**
     * A prefilled repo whose detection hasn't run yet. Set when the form is
     * seeded during mount(); consumed by {@see runPendingAutoDetect} via the
     * blade's wire:init so the repo clone lands after first paint.
     */
    public bool $autoDetectPending = false;

    /**
     * True only while mount() is running. Lets the prefill paths tell "seeded
     * on page load" (defer the clone — it must not block first paint) from
     * "operator clicked a demo tile" (a round trip is already in flight, so
     * detect inline and let the button spin). Not a public property, so it is
     * never hydrated — every subsequent request starts false, which is exactly
     * what the click path wants.
     */
    private bool $mounting = false;

    /** The shaferllc demo repo behind the one-click PHP demo. */
    public const PHP_DEMO_REPO = 'shaferllc/dply-demo-php-function';

    public const PHP_DEMO_BRANCH = 'master';

    /** The shaferllc demo repo behind the one-click Laravel demo. */
    public const LARAVEL_DEMO_REPO = 'shaferllc/dply-demo-laravel-function';

    public const LARAVEL_DEMO_BRANCH = 'master';

    /**
     * Prefill the form with the one-click PHP demo — a minimal native-PHP
     * DigitalOcean Functions web action. The operator just picks a region
     * and credential, then hits Create.
     */
    public function loadPhpDemo(): void
    {
        $this->name = 'PHP demo';
        $this->applyDemoRepository(self::PHP_DEMO_REPO, self::PHP_DEMO_BRANCH);
        $this->runtime = 'php:8.3';
        // The demo pins a deliberate runtime — don't let a later Detect run
        // stomp it.
        $this->runtimeOverridesTouched = true;
    }

    /**
     * Prefill the form with the one-click Laravel demo — a real Laravel app
     * that runs on DigitalOcean Functions' native PHP runtime. dply injects
     * the OpenWhisk↔Laravel adapter at deploy time, so the repo itself is
     * plain Laravel.
     */
    public function loadLaravelDemo(): void
    {
        $this->name = 'Laravel demo';
        $this->applyDemoRepository(self::LARAVEL_DEMO_REPO, self::LARAVEL_DEMO_BRANCH);
        // Laravel 13 requires PHP >= 8.4 — running it on php:8.3 trips
        // composer's platform check before the app can even autoload.
        $this->runtime = 'php:8.4';
        $this->runtimeOverridesTouched = true;
    }

    /**
     * Switch the shared Git picker to paste-URL mode and seed owner/repo + branch
     * without hitting the public scan API (owner/repo shorthand skips the scan).
     */
    private function applyDemoRepository(string $ownerRepo, string $branch): void
    {
        $this->repo_source = 'manual';
        $this->source_control_account_id = '';
        $this->repository_selection = '';
        $this->availableRepositories = [];
        $this->git_repository_url = $ownerRepo;
        $this->git_branch = $branch;
        $this->git_ref_kind = 'branch';
        $this->repoScanState = 'idle';
        $this->refPickerOpen = false;

        // Picking a repo through the Git picker auto-detects (see
        // ManagesServerlessCreateGit's onRepositorySelected / onManualRepoUrlChanged);
        // a demo prefill is just as much "a repo was chosen", so it detects too
        // rather than leaving the panel empty until the operator clicks Detect.
        // The demo pins its own runtime via runtimeOverridesTouched, so the
        // result only populates the panel — it never stomps that pin.
        if ($this->mounting) {
            $this->autoDetectPending = true;

            return;
        }

        $this->detectFromRepository();
    }

    /**
     * Run the detection a mount-time prefill deferred. Fired by wire:init, so
     * the page paints first and the clone happens in a follow-up request —
     * detection can take tens of seconds on a big repo and must never sit in
     * the initial render path. No-ops on every later call.
     */
    public function runPendingAutoDetect(): void
    {
        if (! $this->autoDetectPending) {
            return;
        }

        $this->autoDetectPending = false;

        if (trim($this->git_repository_url) === '') {
            return;
        }

        $this->detectFromRepository();
    }

    /**
     * URL-first detection — clone the repo and surface the detected
     * framework / runtime in the shared panel. Non-blocking: a clone failure
     * lands in `$detectedPlan['error']` and never blocks {@see create()}.
     */
    public function detectFromRepository(): void
    {
        $accountId = $this->repo_source === 'provider' && $this->source_control_account_id !== ''
            ? $this->source_control_account_id
            : null;

        $this->runServerlessDetection(
            $this->normalizeToCloneUrl($this->git_repository_url),
            $this->git_branch,
            '',
            app(ServerlessTargetCapabilityResolver::class)->forDigitalOceanFunctions(),
            $accountId,
            $this->git_ref_kind,
        );
    }

    public function updatedRuntime(): void
    {
        $this->runtimeOverridesTouched = true;
    }

    /**
     * Pre-fill the runtime dropdown from the detected runtime — but only when
     * the detected value is a known dropdown option and the user hasn't
     * already picked one. Anything else leaves the dropdown on `auto`, which
     * defers to the deploy-time detector.
     */
    protected function applyDetectedRuntimePrefills(): void
    {
        if ($this->runtimeOverridesTouched) {
            return;
        }

        $detected = (string) ($this->detectedPlan['runtime'] ?? '');
        if ($detected !== '' && array_key_exists($detected, $this->runtimeOptions())) {
            $this->runtime = $detected;
        }
    }

    public function create(CreateServerlessFunction $action, ServerlessCreateGate $gate): mixed
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return null;
        }
        $this->authorize('update', $org);

        // A stray "managed" pick when the option isn't actually available
        // would fail downstream — coerce it back to BYO so the gate and the
        // validation below both reflect what the user can really do.
        if ($this->delivery_mode === 'managed' && ! $this->managedAvailable()) {
            $this->delivery_mode = 'byo';
        }
        $managed = $this->delivery_mode === 'managed';

        // Every create precondition — surface flag, role, function quota,
        // billing pause, region, credential health — lives in one gate that
        // the API create and its dry run call too, so the two surfaces cannot
        // disagree about who may provision billable infrastructure.
        $blocker = $gate->check(auth()->user(), $org, [
            'delivery_mode' => $this->delivery_mode,
            'provider_credential_id' => $this->provider_credential_id,
            'region' => $this->region,
        ], ServerlessCreateGate::CONTEXT_WEB);

        if ($blocker !== null) {
            if ($blocker->code === SiteCreateBlocker::TRIAL_PAUSED) {
                $this->dispatch('billing-paused');
            }

            // Credential problems belong on the field, not in a toast.
            if (in_array($blocker->code, [
                SiteCreateBlocker::NO_PROVIDER_CREDENTIAL,
                SiteCreateBlocker::CREDENTIAL_UNHEALTHY,
            ], true)) {
                $this->addError('provider_credential_id', $blocker->message);

                return null;
            }

            $this->toastError($blocker->message);

            return null;
        }

        // Validate the credential by row, scoped to org + provider. The action
        // re-checks the same constraint as defense-in-depth (in case a future
        // caller skips Livewire), but doing it here gives inline form errors
        // instead of a toast and an aborted create. Managed functions run on
        // dply's namespace, so they need no customer credential.
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'repo_source' => ['required', 'in:manual,provider'],
            'git_repository_url' => ['required', 'string', 'max:500'],
            'git_branch' => ['required', 'string', 'max:255'],
            'git_ref_kind' => ['nullable', 'in:branch,tag,commit'],
            'runtime' => ['required', 'string', Rule::in(array_keys($this->runtimeOptions()))],
            'region' => ['required', 'string', 'max:32'],
            'delivery_mode' => ['required', 'string', Rule::in(['byo', 'managed'])],
        ];
        if ($this->repo_source === 'provider') {
            $rules['source_control_account_id'] = ['required', 'string', 'max:26'];
            $rules['repository_selection'] = ['required', 'string', 'max:500'];
        }
        if (! $managed) {
            $rules['provider_credential_id'] = [
                'required',
                'string',
                Rule::exists('provider_credentials', 'id')->where(fn ($q) => $q
                    ->where('organization_id', $org->id)
                    ->where('provider', 'digitalocean')),
            ];
        }

        $this->validate($rules, [
            'provider_credential_id.required' => __('Choose a DigitalOcean credential — the namespace cannot be provisioned without one.'),
            'provider_credential_id.exists' => __('That DigitalOcean credential isn\'t available to this organization. Pick one from the list or add a new one under /credentials.'),
        ]);

        if (! $managed && $this->selectedCredentialIsUnhealthy($org->id)) {
            $this->addError(
                'provider_credential_id',
                __('That DigitalOcean credential can’t connect. Replace it at /credentials, or pick a working token.'),
            );

            return null;
        }

        try {
            $site = $action->handle(auth()->user(), $org, [
                'name' => $this->name,
                'repo' => $this->git_repository_url,
                'branch' => $this->git_branch,
                'git_ref_kind' => $this->git_ref_kind ?: 'branch',
                'repo_source' => $this->repo_source,
                'source_control_account_id' => $this->repo_source === 'provider' ? $this->source_control_account_id : null,
                'runtime' => $this->runtime,
                'region' => $this->region,
                'delivery_mode' => $this->delivery_mode,
                'provider_credential_id' => $this->provider_credential_id,
            ]);
        } catch (Throwable $e) {
            $this->toastError(__('Could not create the app: :msg', ['msg' => $e->getMessage()]));

            return null;
        }

        $this->toastSuccess(__('Serverless app created — deploying now.'));

        return $this->redirect(
            ServerlessWorkspaceUrl::journey($site),
            navigate: true,
        );
    }

    /**
     * Runtime choices for the create form. `auto` defers to the deploy-time
     * detector; the rest pin a specific DigitalOcean Functions runtime.
     *
     * @return array<string, string>
     */
    private function runtimeOptions(): array
    {
        return [
            'auto' => __('Auto-detect (recommended)'),
            'nodejs:18' => 'Node.js 18',
            'nodejs:20' => 'Node.js 20',
            'php:8.3' => 'PHP 8.3',
            'php:8.4' => 'PHP 8.4',
            'php:8.5' => 'PHP 8.5',
            'python:3.11' => 'Python 3.11',
            'go:1.22' => 'Go 1.22',
        ];
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();

        $credentials = $org === null ? collect() : ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'validation_error']);

        return view('livewire.serverless.create', [
            'credentials' => $credentials,
            // Creating a function immediately provisions billable infrastructure
            // and deploys, so a pause-blocked org can't start one at all. Shown
            // as a disabled form rather than a rejection on submit — the whole
            // point is that nothing gets stood up.
            'organization' => $org,
            'deploysPaused' => $org !== null && ! $org->canDeploy(),
            'functionFee' => app(ServerlessCostEstimator::class)->functionFee(),
            'managedAvailable' => $this->managedAvailable(),
            'runtimes' => $this->runtimeOptions(),
            'regions' => [
                'nyc1' => 'New York',
                'sfo3' => 'San Francisco',
                'ams3' => 'Amsterdam',
                'fra1' => 'Frankfurt',
                'syd1' => 'Sydney',
            ],
        ]);
    }
}
