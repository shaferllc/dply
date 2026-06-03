<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\RunSiteDeploymentJob;
use App\Jobs\RunSiteFixerJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Sites\Concerns\ManagesSiteDeployExecution;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\Sites\SiteFixers;
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

    /** Console-action id + fixer key of a smart-fix running from the drawer. */
    public ?string $fixerRunId = null;

    public ?string $fixerRunKey = null;

    public function mount(): void
    {
        $site = request()->route('site');
        $server = request()->route('server');

        $this->site = $site instanceof Site ? $site : null;
        $this->server = $server instanceof Server ? $server : $this->site?->server;

        $this->restoreInFlightFixer();
    }

    /**
     * Re-attach to a smart-fix that's still queued/running for this site so its
     * "Processing…" state and live output survive a page reload (the job and its
     * ConsoleAction keep running in the background regardless of the page).
     */
    protected function restoreInFlightFixer(): void
    {
        if ($this->site === null) {
            return;
        }

        $run = ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->where('kind', 'site_remediate')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->latest()
            ->first();

        if ($run === null) {
            return;
        }

        $this->fixerRunId = (string) $run->id;
        $this->fixerRunKey = SiteFixers::keyForLabel((string) $run->label);
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

    /**
     * Run a smart fixer detected from the failed deploy output (e.g. "npm not
     * found" → Install Node.js & npm), right from the deploy console. The fix
     * streams to the page-top console banner; after it finishes, re-deploy.
     */
    public function runFixer(string $key): void
    {
        if ($this->site === null) {
            return;
        }
        Gate::authorize('update', $this->site);

        $spec = SiteFixers::spec($key);
        if ($spec === null) {
            return;
        }

        $run = ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => 'site_remediate',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => (string) $spec['label'],
            'user_id' => auth()->id() ?? 0,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        RunSiteFixerJob::dispatch((string) $run->id, (string) $this->site->id, $key);

        $this->fixerRunId = (string) $run->id;
        $this->fixerRunKey = $key;
        $this->dispatch('deploy-console-open');
    }

    /**
     * The console-action of the smart-fix currently (or last) run from the
     * drawer, so its live output can stream inline.
     */
    #[Computed]
    public function fixerRun(): ?ConsoleAction
    {
        return $this->fixerRunId ? ConsoleAction::query()->find($this->fixerRunId) : null;
    }

    /**
     * Fixer keys that have already completed for THIS failed deploy, so they can
     * be dropped from the "Suggested fixes" list — once a fix has run we don't
     * need to keep offering it. Scoped to fixes run after the deploy finished so
     * a recurrence of the same error still surfaces the fix again.
     *
     * @return list<string>
     */
    #[Computed]
    public function completedFixerKeys(): array
    {
        if ($this->site === null) {
            return [];
        }

        $since = $this->latestDeployment?->finished_at ?? $this->latestDeployment?->created_at;

        $query = ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->where('kind', 'site_remediate')
            ->where('status', ConsoleAction::STATUS_COMPLETED);

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query->get(['label'])
            ->map(fn (ConsoleAction $run): ?string => SiteFixers::keyForLabel((string) $run->label))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.sites.deploy-control');
    }
}
