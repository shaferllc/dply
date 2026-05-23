<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Actions\Edge\CreateEdgeSite;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ProviderCredential;
use App\Services\Billing\ManagedProductCostEstimator;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Contracts\View\View;
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

    /** managed = dply platform; byo = org Cloudflare credential */
    public string $delivery_mode = 'managed';

    public string $edge_provider_credential_id = '';

    public bool $buildOverridesTouched = false;

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
            'origin_url' => ['required_if:runtime_mode,hybrid', 'nullable', 'string', 'max:500'],
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
    }

    public function deploy(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

        if ($this->detectedPlan !== [] && $this->detectedPlanLooksLikeSsr($this->detectedPlan) && $this->runtime_mode !== 'hybrid') {
            $this->toastError(__('This repository looks like an SSR app. Choose hybrid mode with an origin URL, configure static export, or use dply Cloud for full server workloads.'));

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

    /**
     * @param  array<string, mixed>  $plan
     */
    private function detectedPlanLooksLikeSsr(array $plan): bool
    {
        $framework = strtolower((string) ($plan['framework'] ?? ''));
        if (! in_array($framework, ['next', 'nuxt', 'remix', 'sveltekit'], true)) {
            return false;
        }

        $start = strtolower((string) ($plan['start_command'] ?? ''));
        if ($start === '') {
            return false;
        }

        if (str_contains($start, 'export') || str_contains($start, 'generate')) {
            return false;
        }

        $build = strtolower((string) ($plan['build_command'] ?? ''));
        if (str_contains($build, ' export') || str_contains($build, 'generate')) {
            return false;
        }

        return true;
    }

    private function loadRepositoriesForSelectedAccount(): void
    {
        if ($this->source_control_account_id === '') {
            $this->availableRepositories = [];

            return;
        }

        $account = auth()->user()?->socialAccounts()->find($this->source_control_account_id);
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
        ])->layout('layouts.app');
    }
}
