<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Actions\Edge\CreateEdgeSite;
use App\Livewire\Concerns\DetectsRepositoryRuntime;
use App\Livewire\Concerns\DispatchesToastNotifications;
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

    public string $repo = '';

    public string $branch = 'main';

    public string $build_command = '';

    public string $output_dir = '';

    public bool $spa_fallback = true;

    public bool $deploy_on_push = true;

    public bool $buildOverridesTouched = false;

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
        ];
    }

    public function mount(): void
    {
        abort_unless(Feature::active('surface.edge'), 404);

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));
        }
    }

    public function detectFromRepository(): void
    {
        $this->runDetection($this->normalizeToCloneUrl($this->repo), $this->branch);
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

        $framework = strtolower((string) ($this->detectedPlan['framework'] ?? ''));
        if ($this->output_dir === '' || $this->output_dir === 'dist') {
            $this->output_dir = match ($framework) {
                'next' => 'out',
                'nuxt' => '.output/public',
                'astro' => 'dist',
                'vite', 'vue', 'react' => 'dist',
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

        if ($this->detectedPlan !== [] && $this->detectedPlanLooksLikeSsr($this->detectedPlan)) {
            $this->toastError(__('This repository looks like an SSR app (server-rendered Next.js, Nuxt, Remix, or SvelteKit). dply Edge v1 supports static export and SSG only — configure static output in your framework, or use dply Cloud for server workloads.'));

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
                'runtime_mode' => 'static',
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

    public function render(): View
    {
        return view('livewire.edge.create', [
            'fakeEdgeActive' => FakeEdgeProvision::enabled(),
        ])->layout('layouts.app');
    }
}
