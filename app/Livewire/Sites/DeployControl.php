<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Sites\Concerns\ManagesSiteDeployExecution;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Persistent "Deploy" button + live console, mounted in the shared breadcrumb
 * chrome so a deploy can be kicked off — and watched — from ANY site-workspace
 * page (not just the Deploy tab). Resolves the current site from the route, so
 * it's self-contained: drop it next to the Documentation link and it works
 * everywhere a site is in scope.
 *
 * Mirrors {@see ManagesSiteDeployExecution::deployNow()}:
 * seeds the same Cache deploy-lock marker and dispatches the same job, so this
 * and the Deploy tab share one source of truth for "is a deploy running".
 */
class DeployControl extends Component
{
    use DispatchesToastNotifications;

    public ?Site $site = null;

    public ?Server $server = null;

    public function mount(): void
    {
        $site = request()->route('site');
        $server = request()->route('server');

        $this->site = $site instanceof Site ? $site : null;
        $this->server = $server instanceof Server ? $server : $this->site?->server;
    }

    #[Computed]
    public function canDeploy(): bool
    {
        return $this->site !== null
            && $this->server !== null
            && $this->server->isVmHost()
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesEdgeRuntime()
            && Gate::allows('update', $this->site);
    }

    /**
     * @return array{deployment_id?: string}|null
     */
    #[Computed]
    public function deployLockInfo(): ?array
    {
        return $this->site ? Cache::get('site-deploy-active:'.$this->site->id) : null;
    }

    #[Computed]
    public function latestDeployment(): ?SiteDeployment
    {
        return $this->site?->deployments()->latest()->first();
    }

    public function deploy(): void
    {
        if (! $this->canDeploy()) {
            return;
        }

        Gate::authorize('update', $this->site);

        Cache::put('site-deploy-active:'.$this->site->id, [
            'started_at' => now()->toIso8601String(),
            'deployment_id' => null,
        ], 600);

        RunSiteDeploymentJob::dispatch($this->site->fresh(), SiteDeployment::TRIGGER_MANUAL);

        // Drop memoized computed props so the button immediately reads "Deploying…".
        unset($this->deployLockInfo, $this->latestDeployment);

        $this->toastSuccess(__('Deployment queued — watch the console.'));
        $this->dispatch('deploy-console-open');
    }

    public function render()
    {
        return view('livewire.sites.deploy-control');
    }
}
