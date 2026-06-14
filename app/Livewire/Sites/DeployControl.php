<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Actions\Sites\ScheduleSiteDeploy;
use App\Jobs\RunSiteDeploymentJob;
use App\Jobs\RunSiteFixerJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\GuardsBilledDeploys;
use App\Models\ConsoleAction;
use App\Models\ScheduledDeploy;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\Sites\DeployConsoleRows;
use App\Support\Sites\SiteFixers;
use App\Support\Sites\SiteSyncPeers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    use GuardsBilledDeploys;

    public ?Site $site = null;

    public ?Server $server = null;

    /** Console-action id + fixer key of a smart-fix running from the drawer. */
    public ?string $fixerRunId = null;

    public ?string $fixerRunKey = null;

    /** Peer site ids selected in the Sync drawer to deploy together. */
    public array $syncSelected = [];

    /** Peer site ids launched in the active sync batch — drives the live console. */
    public array $syncedSiteIds = [];

    public function mount(): void
    {
        $site = request()->route('site');
        $server = request()->route('server');

        $this->site = $site instanceof Site ? $site : null;
        $this->server = $server instanceof Server ? $server : $this->site?->server;

        $this->restoreInFlightFixer();

        // Default the Sync selection to every peer (this site + its workers).
        $this->syncSelected = $this->syncPeers->pluck('id')->map(fn ($id): string => (string) $id)->all();

        // Re-attach to an in-flight sync batch so the combined console (and its
        // live polling) survives a page reload — same idea as restoreInFlightFixer.
        $batch = $this->site ? Cache::get('site-sync-batch:'.$this->site->id) : null;
        if (is_array($batch) && is_array($batch['ids'] ?? null)) {
            $this->syncedSiteIds = array_values(array_map('strval', $batch['ids']));
        }
    }

    /**
     * Re-run the exact batch shown in the finished console — one click to ship
     * the same peers again. deploySelected() reads $syncSelected, so point it at
     * the current batch and reuse that path (re-dispatch, re-arm polling, etc.).
     */
    public function deployAgain(): void
    {
        if ($this->syncedSiteIds === []) {
            return;
        }
        $this->syncSelected = $this->syncedSiteIds;
        $this->deploySelected();
    }

    /** Clear the active sync batch and return the drawer to peer selection. */
    public function newSync(): void
    {
        $this->syncedSiteIds = [];
        if ($this->site) {
            Cache::forget('site-sync-batch:'.$this->site->id);
        }
        $this->syncSelected = $this->syncPeers->pluck('id')->map(fn ($id): string => (string) $id)->all();
        unset($this->syncRows);
    }

    /**
     * Live per-peer rows for the combined sync console: each launched peer with
     * its latest deployment, phase timeline, and in-flight state. Server is a
     * display-only partial load (no auth happens here).
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function syncRows(): array
    {
        return DeployConsoleRows::forSiteIds($this->syncedSiteIds, (string) ($this->site?->id ?? ''));
    }

    /** Whether any peer in the active sync batch is still deploying. */
    #[Computed]
    public function syncInProgress(): bool
    {
        return DeployConsoleRows::anyInProgress($this->syncRows);
    }

    /**
     * Related sites that can be deployed together — this site plus any sharing
     * its Git repository (or the same server when no repo is set). Mirrors the
     * Sync tab's candidate query; the FULL server is loaded so the update policy
     * authorises each peer (a partial server:id,name load nulls the policy
     * columns and silently skips every site).
     *
     * @return Collection<int, Site>
     */
    #[Computed]
    public function syncPeers(): Collection
    {
        if ($this->site === null) {
            return collect();
        }

        return SiteSyncPeers::forSite($this->site);
    }

    /**
     * Queue a deploy for every selected peer that the user can update. Mirrors
     * DeploymentsList::deployMultiple — the persistent, deploy-from-anywhere
     * twin of the Sync tab.
     */
    public function deploySelected(): void
    {
        $ids = array_values(array_unique(array_map('strval', $this->syncSelected)));
        if ($ids === []) {
            $this->toastError(__('Pick at least one site to deploy.'));

            return;
        }

        $peers = $this->syncPeers->keyBy(fn (Site $s): string => (string) $s->id);
        $queuedIds = [];
        $skipped = 0;
        foreach ($ids as $id) {
            $peer = $peers->get($id);
            if ($peer === null || ! Gate::allows('update', $peer)) {
                $skipped++;

                continue;
            }
            Cache::put('site-deploy-active:'.$peer->id, [
                'started_at' => now()->toIso8601String(),
                'deployment_id' => null,
            ], 600);
            RunSiteDeploymentJob::dispatch($peer->fresh(), SiteDeployment::TRIGGER_MANUAL);
            $queuedIds[] = (string) $peer->id;
        }

        // Drive the combined live console for exactly the peers that launched,
        // and persist the batch so the console survives a page reload.
        $this->syncedSiteIds = $queuedIds;
        if ($queuedIds !== [] && $this->site) {
            Cache::put('site-sync-batch:'.$this->site->id, [
                'ids' => $queuedIds,
                'started_at' => now()->toIso8601String(),
            ], 1800);
        }
        unset($this->deployLockInfo, $this->latestDeployment, $this->syncRows);

        $msg = trans_choice('{1}:count deployment queued.|[2,*]:count deployments queued.', count($queuedIds), ['count' => count($queuedIds)]);
        if ($skipped > 0) {
            $msg .= ' '.__(':n skipped (no permission).', ['n' => $skipped]);
        }
        $this->toastSuccess($msg);
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

    /**
     * Whether the deploy button should show the spinning "Deploying…" state.
     *
     * A running/queued deployment always counts. The optimistic deploy lock (set
     * on click, 600s TTL) only bridges the brief gap before the queued job
     * creates a deployment row — so it must NOT keep the button spinning after a
     * failure or when the job never starts. We therefore stop as soon as THIS
     * run lands a terminal status, and otherwise honour the lock only within the
     * queue-pickup window. Without this a failed/stuck deploy spins for the full
     * 600s lock TTL (or forever if the job is killed without recording failure).
     */
    #[Computed]
    public function inProgress(): bool
    {
        $latest = $this->latestDeployment;

        if ($latest?->status === SiteDeployment::STATUS_RUNNING) {
            return true;
        }

        $lock = $this->deployLockInfo;
        $startedAt = isset($lock['started_at']) ? Carbon::parse($lock['started_at']) : null;
        if ($startedAt === null) {
            return false;
        }

        $terminalSinceLock = $latest !== null
            && in_array($latest->status, [
                SiteDeployment::STATUS_FAILED,
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_SKIPPED,
            ], true)
            && $latest->created_at?->greaterThanOrEqualTo($startedAt->subSeconds(2));

        return ! $terminalSinceLock && $startedAt->greaterThan(now()->subSeconds(90));
    }

    public function deploy(): void
    {
        if (! $this->canDeploy()) {
            return;
        }

        if ($this->blockedByDeployPause($this->site)) {
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

    /** The site's pending one-off delayed deploy, shown/cancelable from anywhere. */
    #[Computed]
    public function pendingScheduledDeploy(): ?ScheduledDeploy
    {
        return $this->site ? app(ScheduleSiteDeploy::class)->pendingFor($this->site) : null;
    }

    public function scheduleDeploy(string $when): void
    {
        if (! $this->canDeploy()) {
            return;
        }
        if ($this->blockedByDeployPause($this->site)) {
            return;
        }
        Gate::authorize('update', $this->site);

        $scheduled = app(ScheduleSiteDeploy::class)->schedule($this->site, $when, auth()->id());
        if ($scheduled === null) {
            $this->toastError(__('Pick a time in the future to schedule the deploy.'));

            return;
        }

        unset($this->pendingScheduledDeploy);
        $this->toastSuccess(__('Deploy scheduled :when.', ['when' => $scheduled->run_at->diffForHumans()]));
    }

    public function cancelScheduledDeploy(): void
    {
        if ($this->site === null) {
            return;
        }
        Gate::authorize('update', $this->site);

        app(ScheduleSiteDeploy::class)->cancelPending($this->site);

        unset($this->pendingScheduledDeploy);
        $this->toastSuccess(__('Scheduled deploy canceled.'));
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
            'user_id' => auth()->id(),
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
