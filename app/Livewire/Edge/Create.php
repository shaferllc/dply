<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Actions\Edge\CreateEdgeSite;
use App\Actions\Edge\CreateHybridEdgeStack;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Billing\ManagedProductCostEstimator;
use App\Services\Cloud\CloudRouter;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Support\Edge\EdgeSsrDetection;
use App\Support\Edge\FakeEdgeProvision;
use App\Support\Edge\HybridEdgeOriginMatcher;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Component;

/**
 * Git-connected create flow for dply Edge — static/SSG builds only in v1.
 */
class Create extends Component
{
    use DetectsRepositoryRuntime;
    use DispatchesToastNotifications;

    public string $name = '';

    /** 'manual' = type owner/name. 'connected' = pick from linked Git account. */
    public string $repo_source = 'manual';

    public string $source_control_account_id = '';

    public string $repository_selection = '';

    public string $repo = '';

    public string $branch = 'main';

    /**
     * @var list<array{id: string, provider: string, label: string}>
     */
    public array $linkedSourceControlAccounts = [];

    /**
     * @var list<array{label: string, url: string, branch: string}>
     */
    public array $availableRepositories = [];

    public string $build_command = '';

    public string $output_dir = '';

    public bool $spa_fallback = true;

    public bool $deploy_on_push = true;

    public string $runtime_mode = 'static';

    public string $origin_url = '';

    public string $origin_cloud_site_id = '';

    public bool $runtimeModeTouched = false;

    public bool $originUrlTouched = false;

    /** managed = dply platform; byo = org Cloudflare credential */
    public string $delivery_mode = 'managed';

    public string $edge_provider_credential_id = '';

    public bool $buildOverridesTouched = false;

    public bool $confirmingHybridStack = false;

    private bool $prefillingOrigin = false;

    private bool $prefillingFromDetection = false;

    private string $lastDetectionFingerprint = '';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'repo' => ['required', 'string', 'max:200'],
            'branch' => ['required', 'string', 'max:120'],
            'build_command' => ['nullable', 'string', 'max:500'],
            'output_dir' => ['nullable', 'string', 'max:200'],
            'spa_fallback' => ['boolean'],
            'deploy_on_push' => ['boolean'],
            'runtime_mode' => ['required', 'in:static,hybrid'],
            'origin_url' => ['nullable', 'string', 'max:500'],
            'delivery_mode' => ['required', 'in:managed,byo'],
            'edge_provider_credential_id' => ['required_if:delivery_mode,byo', 'nullable', 'string'],
        ];
    }

    public function mount(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        abort_unless(Feature::active('surface.edge'), 404);

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());
        if ($this->linkedSourceControlAccounts !== []) {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            $this->loadRepositoriesForSelectedAccount();
            $this->repo_source = 'connected';
        }
    }

    public function updatedRepoSource(string $value): void
    {
        if ($value === 'manual') {
            $this->repository_selection = '';
            $this->maybeAutoDetectFromRepository();

            return;
        }

        if ($this->repository_selection !== '') {
            return;
        }

        $this->runDetection('', $this->branch);
    }

    public function updatedRepo(): void
    {
        if ($this->repo_source !== 'manual') {
            return;
        }

        $this->maybeAutoDetectFromRepository();
    }

    public function updatedBranch(): void
    {
        if ($this->repo_source === 'connected') {
            if (trim($this->repo) !== '') {
                $this->detectFromRepository();
            }

            return;
        }

        $this->maybeAutoDetectFromRepository();
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

        $this->detectFromRepository();
    }

    public function detectFromRepository(): void
    {
        $this->runDetection($this->normalizeToCloneUrl($this->repo), $this->branch);
    }

    private function maybeAutoDetectFromRepository(): void
    {
        $repo = trim($this->repo);
        $branch = trim($this->branch) !== '' ? trim($this->branch) : 'main';

        if ($repo === '') {
            $this->lastDetectionFingerprint = '';
            $this->runDetection('', $branch);

            return;
        }

        if (! $this->repoLooksComplete($repo) || $branch === '') {
            return;
        }

        $fingerprint = $this->normalizeToCloneUrl($repo).'|'.$branch;
        if ($fingerprint === $this->lastDetectionFingerprint) {
            return;
        }

        $this->lastDetectionFingerprint = $fingerprint;
        $this->detectFromRepository();
    }

    private function repoLooksComplete(string $repo): bool
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $repo) === 1 || str_starts_with($repo, 'git@')) {
            return true;
        }

        $normalized = trim($repo, '/');
        if (! str_contains($normalized, '/')) {
            return false;
        }

        [$owner, $name] = array_pad(explode('/', $normalized, 2), 2, '');

        return trim($owner) !== '' && trim($name) !== '';
    }

    public function updatedName(): void
    {
        if ($this->prefillingFromDetection) {
            return;
        }

        if ($this->runtime_mode === 'hybrid' && ! $this->originUrlTouched) {
            $this->applyHybridOriginSuggestion();
        }
    }

    public function updatedRuntimeMode(): void
    {
        if ($this->prefillingFromDetection) {
            return;
        }

        $this->runtimeModeTouched = true;

        if ($this->runtime_mode === 'hybrid') {
            $this->applyHybridOriginSuggestion();
        }
    }

    public function updatedOriginUrl(): void
    {
        if ($this->prefillingOrigin) {
            return;
        }

        $this->originUrlTouched = true;
    }

    public function updatedOriginCloudSiteId(string $value): void
    {
        if ($value === '') {
            if (! $this->originUrlTouched) {
                $this->prefillingOrigin = true;
                $this->origin_url = '';
                $this->prefillingOrigin = false;
            }

            return;
        }

        $site = $this->findOrgCloudSite($value);
        if ($site === null) {
            return;
        }

        $liveUrl = $site->containerLiveUrl();
        $this->origin_url = $liveUrl ?? '';
        $this->originUrlTouched = $liveUrl !== null;
    }

    public function updatedBuildCommand(): void
    {
        $this->buildOverridesTouched = true;
    }

    public function updatedOutputDir(): void
    {
        $this->buildOverridesTouched = true;
    }

    protected function applyDetectedRuntimePrefills(): void
    {
        if ($this->buildOverridesTouched) {
            return;
        }

        $build = trim((string) ($this->detectedPlan['build_command'] ?? ''));
        if ($build !== '') {
            $this->build_command = $build;
        }

        $detectedOutput = trim((string) ($this->detectedPlan['output_dir'] ?? ''));
        if ($detectedOutput !== '') {
            $this->output_dir = $detectedOutput;

            return;
        }

        $framework = strtolower((string) ($this->detectedPlan['framework'] ?? ''));
        if ($this->output_dir === '' || $this->output_dir === 'dist') {
            $this->output_dir = match ($framework) {
                'next' => 'out',
                'nuxt' => '.output/public',
                'astro' => 'dist',
                'eleventy', 'jekyll' => '_site',
                'hugo' => 'public',
                'static' => '.',
                'vite', 'vue', 'react', 'svelte', 'sveltekit', 'remix' => 'dist',
                default => $this->output_dir !== '' ? $this->output_dir : 'dist',
            };
        }

        $this->applyDetectedDeliveryPrefills();
    }

    private function applyDetectedDeliveryPrefills(): void
    {
        if ($this->runtimeModeTouched || $this->detectedPlan === []) {
            return;
        }

        if (! EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)) {
            return;
        }

        if (trim($this->name) === '' && trim($this->repo) !== '') {
            $this->prefillingFromDetection = true;
            $this->name = $this->defaultNameFromRepo();
            $this->prefillingFromDetection = false;
        }

        $this->prefillingFromDetection = true;
        $this->runtime_mode = 'hybrid';
        $this->prefillingFromDetection = false;
        $this->applyHybridOriginSuggestion();
    }

    private function defaultNameFromRepo(): string
    {
        $repo = HybridEdgeOriginMatcher::normalizeRepo(trim($this->repo));
        if ($repo === '') {
            return '';
        }

        $segment = (string) (array_slice(explode('/', $repo), -1)[0] ?? '');

        return Str::title(str_replace(['-', '_'], ' ', $segment));
    }

    private function applyHybridOriginSuggestion(): void
    {
        if ($this->originUrlTouched) {
            return;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $this->prefillingOrigin = true;

        try {
            $repo = HybridEdgeOriginMatcher::normalizeRepo(trim($this->repo));
            if ($repo !== '') {
                $matched = HybridEdgeOriginMatcher::findForRepo($org, $repo);
                if ($matched !== null) {
                    $this->origin_cloud_site_id = (string) $matched->id;
                    $this->origin_url = $matched->containerLiveUrl() ?? '';

                    return;
                }
            }

            $name = trim($this->name) ?: $this->defaultNameFromRepo();
            if ($name === '') {
                $this->origin_cloud_site_id = '';
                $this->origin_url = '';

                return;
            }

            $matched = HybridEdgeOriginMatcher::findForEdgeName($org, $name);
            if ($matched !== null) {
                $this->origin_cloud_site_id = (string) $matched->id;
                $this->origin_url = $matched->containerLiveUrl() ?? '';

                return;
            }

            $this->origin_cloud_site_id = '';
            $this->origin_url = $this->suggestedHybridOriginUrl($name);
        } finally {
            $this->prefillingOrigin = false;
        }
    }

    public function suggestedHybridOriginUrlForName(): string
    {
        $name = trim($this->name);

        return $name !== '' ? $this->suggestedHybridOriginUrl($name) : '';
    }

    private function suggestedHybridOriginUrl(string $name): string
    {
        if ($this->canProvisionCloudOrigin()) {
            return '';
        }

        if (FakeCloudProvision::enabled()) {
            $slug = Str::slug($name) ?: 'app';

            return 'https://'.$slug.'.fake-cloud.dply.local';
        }

        return '';
    }

    private function findOrgCloudSite(string $siteId): ?Site
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return null;
        }

        return Site::query()
            ->where('organization_id', $org->id)
            ->whereIn('container_backend', ['digitalocean_app_platform', 'aws_app_runner', 'dply_cloud'])
            ->find($siteId);
    }

    /**
     * @return list<array{id: string, label: string, live_url: ?string, repo: ?string}>
     */
    private function orgCloudSitesForPicker(): array
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return [];
        }

        return Site::query()
            ->where('organization_id', $org->id)
            ->whereIn('container_backend', ['digitalocean_app_platform', 'aws_app_runner', 'dply_cloud'])
            ->orderBy('name')
            ->get()
            ->map(function (Site $site): array {
                $container = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
                $source = is_array($container['source'] ?? null) ? $container['source'] : [];

                return [
                    'id' => (string) $site->id,
                    'label' => (string) $site->name,
                    'live_url' => $site->containerLiveUrl(),
                    'repo' => is_string($source['repo'] ?? null) ? (string) $source['repo'] : null,
                ];
            })
            ->values()
            ->all();
    }

    public function deploy(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

        if ($this->detectedPlan !== [] && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan) && $this->runtime_mode !== 'hybrid') {
            $this->toastError(__('This repository looks like an SSR app. Choose hybrid mode with an origin URL, configure static export, or use dply Cloud for full server workloads.'));

            return;
        }

        if ($this->runtime_mode === 'hybrid' && trim($this->origin_url) === '' && $this->shouldAutoProvisionHybridOrigin()) {
            $this->deployHybridStack();

            return;
        }

        if ($this->runtime_mode === 'hybrid' && trim($this->origin_url) === '') {
            $this->toastError(__('Enter the SSR origin URL for hybrid delivery.'));

            return;
        }

        $buildCommand = trim($this->build_command);
        $outputDir = trim($this->output_dir);

        try {
            $site = (new CreateEdgeSite)->handle(auth()->user(), $org, [
                'name' => $this->name,
                'repo' => $this->repo,
                'branch' => $this->branch,
                'build_command' => $buildCommand !== '' ? $buildCommand : 'npm ci && npm run build',
                'output_dir' => $outputDir !== '' ? $outputDir : 'dist',
                'spa_fallback' => $this->spa_fallback,
                'deploy_on_push' => $this->deploy_on_push,
                'framework' => (string) ($this->detectedPlan['framework'] ?? ''),
                'runtime_mode' => $this->runtime_mode,
                'origin_url' => trim($this->origin_url),
                'cloud_site_id' => $this->origin_cloud_site_id !== '' ? $this->origin_cloud_site_id : null,
                'origin_routes' => ['/_next/*', '/api/*'],
                'edge_backend' => $this->delivery_mode === 'byo' ? 'org_cloudflare' : 'dply_edge',
                'edge_provider_credential_id' => $this->delivery_mode === 'byo' ? $this->edge_provider_credential_id : null,
            ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Edge app build queued. We\'ll keep the site workspace updated as it goes live.'));
        $this->redirect(route('sites.show', ['server' => $site->server, 'site' => $site]), navigate: true);
    }

    public function openHybridStackModal(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

        $this->confirmingHybridStack = true;
        $this->dispatch('open-modal', 'edge-create-hybrid-stack-confirmation');
    }

    public function closeHybridStackModal(): void
    {
        $this->confirmingHybridStack = false;
        $this->dispatch('close-modal', 'edge-create-hybrid-stack-confirmation');
    }

    public function deployHybridStack(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

        if ($this->detectedPlan !== [] && ! EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)) {
            $this->toastError(__('Hybrid stack deploy is only available for server-rendered JavaScript frameworks.'));

            return;
        }

        $buildCommand = trim($this->build_command);
        $outputDir = trim($this->output_dir);

        try {
            $result = (new CreateHybridEdgeStack)->handle(auth()->user(), $org, [
                'name' => $this->name,
                'repo' => $this->repo,
                'branch' => $this->branch,
                'build_command' => $buildCommand !== '' ? $buildCommand : 'npm ci && npm run build',
                'output_dir' => $outputDir !== '' ? $outputDir : 'dist',
                'spa_fallback' => $this->spa_fallback,
                'deploy_on_push' => $this->deploy_on_push,
                'detected_plan' => $this->detectedPlan,
                'origin_routes' => ['/_next/*', '/api/*'],
                'edge_backend' => $this->delivery_mode === 'byo' ? 'org_cloudflare' : 'dply_edge',
                'edge_provider_credential_id' => $this->delivery_mode === 'byo' ? $this->edge_provider_credential_id : null,
            ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->closeHybridStackModal();

        if ($result['redirect_to'] === 'edge' && $result['edge_site'] instanceof Site) {
            $this->toastSuccess(__('Edge hybrid app build queued. We\'ll keep the site workspace updated as it goes live.'));
            $this->redirect(route('sites.show', ['server' => $result['edge_site']->server, 'site' => $result['edge_site']]), navigate: true);

            return;
        }

        $this->toastSuccess(__('Cloud origin queued. We\'ll create the Edge hybrid app when the origin is live.'));
        $this->redirect(route('sites.show', ['server' => $result['cloud_site']->server, 'site' => $result['cloud_site']]), navigate: true);
    }

    private function canProvisionCloudOrigin(): bool
    {
        if (! Feature::active('surface.cloud')) {
            return false;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return false;
        }

        return CloudRouter::pickAutoBackend($org->id) !== null;
    }

    private function showHybridStackCta(): bool
    {
        return $this->shouldAutoProvisionHybridOrigin();
    }

    private function shouldAutoProvisionHybridOrigin(): bool
    {
        return $this->runtime_mode === 'hybrid'
            && trim($this->origin_url) === ''
            && $this->detectedPlan !== []
            && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)
            && $this->canProvisionCloudOrigin();
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

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $cloudflareCredentials = $org
            ? ProviderCredential::query()
                ->where('organization_id', $org->id)
                ->where('provider', 'cloudflare')
                ->latest()
                ->get()
            : collect();

        return view('livewire.edge.create', [
            'fakeEdgeActive' => FakeEdgeProvision::enabled(),
            'edgeFee' => app(ManagedProductCostEstimator::class)->edgeFee(),
            'edgeUsageBillingEnabled' => app(ManagedProductCostEstimator::class)->edgeUsageBillingEnabled(),
            'edgeUsageRates' => app(ManagedProductCostEstimator::class)->edgeUsageRates(),
            'cloudflareCredentials' => $cloudflareCredentials,
            'orgCloudSites' => $this->orgCloudSitesForPicker(),
            'ssrDetected' => $this->detectedPlan !== [] && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan),
            'suggestedHybridOriginUrl' => $this->suggestedHybridOriginUrlForName(),
            'showHybridStackCta' => $this->showHybridStackCta(),
            'autoProvisionHybridOrigin' => $this->shouldAutoProvisionHybridOrigin(),
            'canProvisionCloudOrigin' => $this->canProvisionCloudOrigin(),
            'cloudFee' => app(ManagedProductCostEstimator::class)->cloudFee(),
        ])->layout('layouts.app');
    }
}
