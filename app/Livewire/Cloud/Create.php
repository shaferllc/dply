<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Actions\Cloud\CreateCloudSite;
use App\Actions\Cloud\CreateCloudSiteFromSource;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ProviderCredential;
use App\Services\Billing\ManagedProductCostEstimator;
use App\Services\Cloud\AwsAppRunnerBackend;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Container app create flow for the dply cloud platform — the
 * "deploy a container globally" UX, replacing the old "Connect
 * Fly.io" upsell with our own primary surface.
 */
class Create extends Component
{
    use DetectsRepositoryRuntime;
    use DispatchesToastNotifications;

    #[Url]
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

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'instances' => ['required', 'integer', 'min:1', 'max:50'],
            'size_tier' => ['required', 'in:small,medium,large,xlarge'],
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

        return $rules;
    }

    public function mount(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        abort_unless(Feature::active('surface.cloud'), 404);

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        // Default region tied to the picked backend.
        $this->updatedBackend($this->backend);

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

    public function deploy(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

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
                ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Container app provisioning. We\'ll keep this page updated as it comes online.'));
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

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $connected = $org === null ? collect() : ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->whereIn('provider', ['digitalocean_app_platform', 'aws_app_runner'])
            ->get(['id', 'provider', 'name', 'credentials']);

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
        ])->layout('layouts.app');
    }
}
