<?php

declare(strict_types=1);

namespace App\Livewire\Serverless;

use App\Actions\Serverless\CreateServerlessFunction;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ProviderCredential;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * One-shot "Create a serverless app" flow — the FaaS counterpart to
 * Edge\Create. Collects a DO credential + region + repo + runtime, hands
 * off to {@see CreateServerlessFunction}, which stands up the host
 * namespace + first function and kicks a deploy.
 */
#[Layout('layouts.app')]
class Create extends Component
{
    use DetectsRepositoryRuntime;
    use DispatchesToastNotifications;

    public string $provider_credential_id = '';

    /**
     * Preselect a DigitalOcean credential. A serverless host with no
     * credential cannot provision its namespace — the deploy dies at
     * `serverless.namespace.no_credential` — so the form must never submit
     * without one.
     */
    public function mount(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $first = ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->value('id');

        if (is_string($first)) {
            $this->provider_credential_id = $first;
        }
    }

    public string $region = 'nyc1';

    public string $name = '';

    public string $repo = '';

    public string $branch = 'main';

    /**
     * Runtime selection. Defaults to `auto` — the deploy-time
     * {@see \App\Services\Deploy\ServerlessRuntimeDetector} picks the runtime
     * from the repository. An explicit value overrides detection.
     */
    public string $runtime = 'auto';

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
        $this->repo = self::PHP_DEMO_REPO;
        $this->branch = self::PHP_DEMO_BRANCH;
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
        $this->repo = self::LARAVEL_DEMO_REPO;
        $this->branch = self::LARAVEL_DEMO_BRANCH;
        // Laravel 13 requires PHP >= 8.4 — running it on php:8.3 trips
        // composer's platform check before the app can even autoload.
        $this->runtime = 'php:8.4';
        $this->runtimeOverridesTouched = true;
    }

    /**
     * URL-first detection — clone the repo and surface the detected
     * framework / runtime in the shared panel. Non-blocking: a clone failure
     * lands in `$detectedPlan['error']` and never blocks {@see create()}.
     */
    public function detectFromRepository(): void
    {
        $this->runServerlessDetection(
            $this->normalizeToCloneUrl($this->repo),
            $this->branch,
            '',
            app(ServerlessTargetCapabilityResolver::class)->forDigitalOceanFunctions(),
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

    public function create(CreateServerlessFunction $action): mixed
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return null;
        }
        $this->authorize('update', $org);

        // Validate the credential by row, scoped to org + provider. The action
        // re-checks the same constraint as defense-in-depth (in case a future
        // caller skips Livewire), but doing it here gives inline form errors
        // instead of a toast and an aborted create.
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'repo' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:255'],
            'runtime' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys($this->runtimeOptions()))],
            'region' => ['required', 'string', 'max:32'],
            'provider_credential_id' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::exists('provider_credentials', 'id')->where(fn ($q) => $q
                    ->where('organization_id', $org->id)
                    ->where('provider', 'digitalocean')),
            ],
        ], [
            'provider_credential_id.required' => __('Choose a DigitalOcean credential — the namespace cannot be provisioned without one.'),
            'provider_credential_id.exists' => __('That DigitalOcean credential isn\'t available to this organization. Pick one from the list or add a new one under /credentials.'),
        ]);

        try {
            $site = $action->handle(auth()->user(), $org, [
                'name' => $this->name,
                'repo' => $this->repo,
                'branch' => $this->branch,
                'runtime' => $this->runtime,
                'region' => $this->region,
                'provider_credential_id' => $this->provider_credential_id,
            ]);
        } catch (Throwable $e) {
            $this->toastError(__('Could not create the function: :msg', ['msg' => $e->getMessage()]));

            return null;
        }

        $this->toastSuccess(__('Serverless function created — deploying now.'));

        return $this->redirect(route('serverless.journey', [$site->server_id, $site->id]), navigate: true);
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
            ->get(['id', 'name']);

        return view('livewire.serverless.create', [
            'credentials' => $credentials,
            'functionFee' => app(\App\Services\Serverless\ServerlessCostEstimator::class)->functionFee(),
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
