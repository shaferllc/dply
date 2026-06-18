<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Actions\Sites\ScheduleSiteDeploy;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\GuardsBilledDeploys;
use App\Models\ConsoleAction;
use App\Models\ScheduledDeploy;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Sites\DeployStatus;
use App\Services\Sites\SiteDeployCoordinator;
use App\Support\Sites\DeployConsoleRows;
use App\Support\Sites\SiteFixers;
use App\Support\Sites\SiteSyncPeers;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Persistent "Deploy" button + live console, mounted in the shared breadcrumb
 * chrome so a deploy can be kicked off — and watched — from ANY site-workspace
 * page (not just the Deploy tab). Resolves the current site from the route, so
 * it's self-contained.
 *
 * Deploy / Sync / smart-fix all run through {@see SiteDeployCoordinator}, the
 * same service the main Deploy page ({@see DeploymentsList}) uses, and both
 * surfaces render from one {@see DeployStatus} snapshot — so they can't drift,
 * double-fire, or disagree on "is a deploy running". The `site-deploy-changed`
 * event keeps the two refreshed in lockstep without waiting on the poll tick.
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

    /** Peer site ids selected in the Sync drawer — mirrored with the Deploy page. */
    public array $syncSelected = [];

    /** Peer site ids launched in the active sync batch — drives the live console. */
    public array $syncedSiteIds = [];

    public function mount(): void
    {
        $site = request()->route('site');
        $server = request()->route('server');

        $this->site = $site instanceof Site ? $site : null;
        $this->server = $server instanceof Server ? $server : $this->site?->server;

        if ($this->site === null) {
            return;
        }

        $coordinator = app(SiteDeployCoordinator::class);

        // Re-attach to an in-flight smart-fix so its "Processing…" state + live
        // output survive a page reload (the job keeps running regardless).
        $fixer = $coordinator->inFlightFixer($this->site);
        if ($fixer !== null) {
            $this->fixerRunId = (string) $fixer->id;
            $this->fixerRunKey = SiteFixers::keyForLabel((string) $fixer->label);
        }

        // Shared, persisted Sync selection (mirrored with the Deploy page).
        $this->syncSelected = $coordinator->selectedPeerIds($this->site);

        // Re-attach to an in-flight sync batch so the combined console survives a reload.
        $this->syncedSiteIds = $coordinator->syncBatch($this->site)['ids'] ?? [];
    }

    /** One snapshot both surfaces render from — the single read-side source of truth. */
    #[Computed]
    public function status(): ?DeployStatus
    {
        return $this->site ? app(SiteDeployCoordinator::class)->status($this->site) : null;
    }

    #[Computed]
    public function latestDeployment(): ?SiteDeployment
    {
        return $this->status?->latest;
    }

    /**
     * @return array{started_at?: string, deployment_id?: ?string}|null
     */
    #[Computed]
    public function deployLockInfo(): ?array
    {
        return $this->status?->lock;
    }

    #[Computed]
    public function inProgress(): bool
    {
        return $this->status?->inProgress ?? false;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function completedFixerKeys(): array
    {
        return $this->status?->completedFixerKeys ?? [];
    }

    #[Computed]
    public function canDeploy(): bool
    {
        return $this->site !== null
            && $this->server !== null
            && $this->server->isVmHost()
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesEdgeRuntime()
            && \Illuminate\Support\Facades\Gate::allows('update', $this->site);
    }

    public function deploy(): void
    {
        if (! $this->canDeploy()) {
            return;
        }
        if ($this->blockedByDeployPause($this->site)) {
            return;
        }

        \Illuminate\Support\Facades\Gate::authorize('update', $this->site);

        $queued = app(SiteDeployCoordinator::class)->deploy(
            $this->site,
            SiteDeployment::TRIGGER_MANUAL,
            (string) (auth()->id() ?? ''),
        );

        $this->refreshDeployState();

        if (! $queued) {
            $this->toastError(__('A deploy is already running for this site.'));

            return;
        }

        $this->toastSuccess(__('Deployment queued — watch the console.'));
        $this->dispatch('deploy-console-open');
        $this->dispatch('site-deploy-changed', siteId: (string) $this->site->id);
    }

    /**
     * Queue a deploy for every selected peer the user can update. Routes through
     * {@see SiteDeployCoordinator::sync()} — the same path the Deploy page's Sync
     * panel uses.
     */
    public function deploySelected(): void
    {
        if ($this->site === null) {
            return;
        }

        $ids = array_values(array_unique(array_map('strval', $this->syncSelected)));
        if ($ids === []) {
            $this->toastError(__('Pick at least one site to deploy.'));

            return;
        }
        if ($this->blockedByDeployPause($this->site)) {
            return;
        }

        $result = app(SiteDeployCoordinator::class)->sync($this->site, $ids, (string) (auth()->id() ?? ''));
        $this->syncedSiteIds = $result['queued'];
        $this->refreshDeployState();

        $msg = trans_choice('{1}:count deployment queued.|[2,*]:count deployments queued.', count($result['queued']), ['count' => count($result['queued'])]);
        if ($result['skipped'] > 0) {
            $msg .= ' '.__(':n skipped (no permission or already running).', ['n' => $result['skipped']]);
        }
        $this->toastSuccess($msg);
        $this->dispatch('site-deploy-changed', siteId: (string) $this->site->id);
    }

    /** Re-run the exact batch shown in the finished console. */
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
        if ($this->site === null) {
            return;
        }
        $this->syncedSiteIds = [];
        app(SiteDeployCoordinator::class)->clearSyncBatch($this->site);
        $this->syncSelected = app(SiteDeployCoordinator::class)->selectedPeerIds($this->site);
        unset($this->syncRows);
    }

    /** Persist (and mirror) the Sync selection whenever the checkboxes change. */
    public function updatedSyncSelected(): void
    {
        if ($this->site === null) {
            return;
        }
        $this->syncSelected = app(SiteDeployCoordinator::class)->setSelectedPeerIds($this->site, $this->syncSelected);
        $this->dispatch('site-deploy-changed', siteId: (string) $this->site->id);
    }

    /**
     * Run a smart fixer detected from the failed deploy output, right from the
     * console. The fix streams inline; after it finishes, re-deploy.
     */
    public function runFixer(string $key): void
    {
        if ($this->site === null) {
            return;
        }
        \Illuminate\Support\Facades\Gate::authorize('update', $this->site);

        $run = app(SiteDeployCoordinator::class)->runFixer($this->site, $key, (string) (auth()->id() ?? ''));
        if ($run === null) {
            $this->toastError(__('A fix is already running — let it finish first.'));

            return;
        }

        $this->fixerRunId = (string) $run->id;
        $this->fixerRunKey = $key;
        $this->refreshDeployState();
        $this->dispatch('deploy-console-open');
        $this->dispatch('site-deploy-changed', siteId: (string) $this->site->id);
    }

    /** Keep the page + sidebar in lockstep when either fires a deploy/sync/fix. */
    #[On('site-deploy-changed')]
    public function onDeployChanged(?string $siteId = null): void
    {
        if ($this->site === null) {
            return;
        }
        if ($siteId !== null && $siteId !== (string) $this->site->id) {
            return;
        }
        $this->refreshDeployState();
    }

    /**
     * Drop the memoized snapshot (and its derived computeds) and re-read the
     * mirrored selection / batch / in-flight fixer so the next render reflects
     * the latest shared state.
     */
    protected function refreshDeployState(): void
    {
        unset(
            $this->status,
            $this->latestDeployment,
            $this->deployLockInfo,
            $this->inProgress,
            $this->completedFixerKeys,
            $this->syncRows,
            $this->fixerRun,
        );

        if ($this->site === null) {
            return;
        }

        $coordinator = app(SiteDeployCoordinator::class);
        $this->syncSelected = $coordinator->selectedPeerIds($this->site);
        $this->syncedSiteIds = $coordinator->syncBatch($this->site)['ids'] ?? $this->syncedSiteIds;

        if ($this->fixerRunId === null) {
            $fixer = $coordinator->inFlightFixer($this->site);
            if ($fixer !== null) {
                $this->fixerRunId = (string) $fixer->id;
                $this->fixerRunKey = SiteFixers::keyForLabel((string) $fixer->label);
            }
        }
    }

    /**
     * Live per-peer rows for the combined sync console.
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
     * Related sites that can ship together — this site plus repo/server peers.
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
     * The console-action of the smart-fix currently (or last) run from the
     * drawer, so its live output can stream inline.
     */
    #[Computed]
    public function fixerRun(): ?ConsoleAction
    {
        return $this->fixerRunId ? ConsoleAction::query()->find($this->fixerRunId) : null;
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
        \Illuminate\Support\Facades\Gate::authorize('update', $this->site);

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
        \Illuminate\Support\Facades\Gate::authorize('update', $this->site);

        app(ScheduleSiteDeploy::class)->cancelPending($this->site);

        unset($this->pendingScheduledDeploy);
        $this->toastSuccess(__('Scheduled deploy canceled.'));
    }

    public function render()
    {
        return view('livewire.sites.deploy-control');
    }
}
