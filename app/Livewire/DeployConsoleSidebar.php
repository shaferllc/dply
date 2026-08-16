<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\WatchesSiteDeploys;
use App\Models\SiteDeployment;
use App\Support\Sites\DeployConsoleRows;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Global deploy-status sidebar — mounted once in the app shell so operators can
 * watch BYO deploys from any page (floating dock) and so fleet kickoffs
 * ({@see WatchesSiteDeploys}) open one shared console
 * focused on the newly queued run instead of a stale finished batch.
 *
 * "Dismiss finished" only hides terminal runs from this sidebar (session
 * watermark). SiteDeployment history elsewhere is never deleted.
 *
 * @property-read list<array<string, mixed>> $watchedRows
 * @property-read bool $watchedInProgress
 * @property-read int $finishedHistoryCount
 */
class DeployConsoleSidebar extends Component
{
    use ConfirmsActionWithModal;

    /** Session key prefix: deploy_console.dismissed_before.{organizationId} */
    public const DISMISSED_BEFORE_SESSION_PREFIX = 'deploy_console.dismissed_before.';

    /** Cap sites shown when browsing active + recent (keeps open cheap). */
    public const BROWSE_SITE_LIMIT = 8;

    /**
     * Site ids currently driving the console (kickoff batch or browse selection).
     *
     * @var list<string>
     */
    public array $watchedSiteIds = [];

    /**
     * True when the console was opened by a Deploy/Sync kickoff for a specific
     * batch. False when opened from the dock in browse mode (active + recent).
     */
    public bool $watchingBatch = false;

    /**
     * Focus the console on the sites just launched and open it. Called from
     * fleet Deploy/Sync surfaces via a Livewire event so a new run always
     * replaces whatever finished batch was showing.
     *
     * @param  list<string|int>  $siteIds
     */
    #[On('deploy-console-focus')]
    public function focusSites(array $siteIds = []): void
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => (string) $id,
            $siteIds,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return;
        }

        $this->watchedSiteIds = $ids;
        $this->watchingBatch = true;
        $this->forgetRowComputeds();
        $this->dispatch('dply-deploy-console-open');
    }

    /**
     * Open from the floating dock (Alpine bridges the browser event to this
     * method): prefer in-flight org deploys, otherwise show recent finished
     * ones so the sidebar is never stuck on a single stale batch.
     */
    public function openBrowse(): void
    {
        $this->watchedSiteIds = $this->discoverBrowseSiteIds();
        $this->watchingBatch = false;
        $this->forgetRowComputeds();
        $this->dispatch('dply-deploy-console-open');
    }

    /** Return the console to active + recent org deploys (from a finished batch). */
    public function showRecent(): void
    {
        $this->watchedSiteIds = $this->discoverBrowseSiteIds();
        $this->watchingBatch = false;
        $this->forgetRowComputeds();
    }

    /** Clear the focused batch (empty state until the next kickoff / browse). */
    public function clearWatch(): void
    {
        $this->watchedSiteIds = [];
        $this->watchingBatch = false;
        $this->forgetRowComputeds();
    }

    /**
     * Confirm dismissing finished (success / failed / skipped) deploys from the
     * sidebar list only. Running deploys stay visible; DB history is kept.
     */
    public function openClearFinishedConfirm(): void
    {
        $count = $this->finishedHistoryCount;
        if ($count === 0) {
            $this->dispatch('notify', message: __('No finished deploys to dismiss.'), type: 'error');

            return;
        }

        $this->openConfirmActionModal(
            'clearFinishedDeployments',
            [],
            __('Dismiss finished deploys'),
            trans_choice(
                '{1}Hide :n finished deploy from this sidebar? Deploy history on site pages and in logs is kept. In-progress deploys stay visible.|[2,*]Hide :n finished deploys from this sidebar? Deploy history on site pages and in logs is kept. In-progress deploys stay visible.',
                $count,
                ['n' => $count],
            ),
            __('Dismiss finished'),
            false,
        );
    }

    /**
     * Hide terminal SiteDeployment rows from this sidebar via a session
     * watermark. Does not delete history. Does not hide
     * {@see SiteDeployment::STATUS_RUNNING} rows.
     */
    public function clearFinishedDeployments(): void
    {
        $orgId = $this->currentOrganizationId();
        if ($orgId === null) {
            $this->dispatch('notify', message: __('No finished deploys to dismiss.'), type: 'error');

            return;
        }

        if ($this->finishedHistoryCount === 0) {
            $this->dispatch('notify', message: __('No finished deploys to dismiss.'), type: 'error');

            return;
        }

        session([self::DISMISSED_BEFORE_SESSION_PREFIX.$orgId => now()->toIso8601String()]);

        $this->watchingBatch = false;
        $this->watchedSiteIds = $this->discoverBrowseSiteIds();
        $this->forgetRowComputeds();

        $this->dispatch(
            'notify',
            message: __('Cleared finished deploys from sidebar'),
            type: 'success',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function watchedRows(): array
    {
        $rows = DeployConsoleRows::forSiteIds($this->watchedSiteIds);
        $dismissedBefore = $this->dismissedBefore();

        if ($dismissedBefore === null) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            function (array $row) use ($dismissedBefore): bool {
                // In-progress (including fresh kickoff lock) never dismissed.
                if ($row['in_progress'] ?? false) {
                    return true;
                }

                $latest = $row['latest'] ?? null;
                if (! $latest instanceof SiteDeployment) {
                    return true;
                }

                return ! $this->deploymentIsDismissed($latest, $dismissedBefore);
            },
        ));
    }

    #[Computed]
    public function watchedInProgress(): bool
    {
        return DeployConsoleRows::anyInProgress($this->watchedRows);
    }

    /**
     * Finished rows currently visible in the sidebar (not a full-org history
     * count — that was an expensive table scan on every open).
     */
    #[Computed]
    public function finishedHistoryCount(): int
    {
        $count = 0;
        foreach ($this->watchedRows as $row) {
            if (! ($row['in_progress'] ?? false)) {
                $count++;
            }
        }

        return $count;
    }

    public function render(): View
    {
        return view('livewire.deploy-console-sidebar');
    }

    /**
     * Active (running) site deploys first, then other sites with a recent
     * deployment — scoped to the current organization. Sites whose latest
     * terminal deploy was dismissed from the sidebar are omitted.
     *
     * @return list<string>
     */
    protected function discoverBrowseSiteIds(): array
    {
        $orgId = $this->currentOrganizationId();
        if ($orgId === null) {
            return [];
        }

        $dismissedBefore = $this->dismissedBefore();

        // Join sites → organization instead of materializing every org site id
        // into a giant WHERE IN (that alone was multi-second on large orgs).
        $runningSiteIds = SiteDeployment::query()
            ->select('site_deployments.site_id')
            ->join('sites', 'sites.id', '=', 'site_deployments.site_id')
            ->where('sites.organization_id', $orgId)
            ->where('site_deployments.status', SiteDeployment::STATUS_RUNNING)
            ->orderByDesc('site_deployments.started_at')
            ->limit(self::BROWSE_SITE_LIMIT)
            ->pluck('site_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        $recentLimit = max(0, self::BROWSE_SITE_LIMIT - $runningSiteIds->count());
        $recentSiteIds = collect();

        if ($recentLimit > 0) {
            $recentSiteIds = SiteDeployment::query()
                ->select('site_deployments.site_id')
                ->join('sites', 'sites.id', '=', 'site_deployments.site_id')
                ->where('sites.organization_id', $orgId)
                ->when($runningSiteIds->isNotEmpty(), fn ($q) => $q->whereNotIn('site_deployments.site_id', $runningSiteIds))
                ->when(
                    $dismissedBefore !== null,
                    function ($q) use ($dismissedBefore) {
                        $q->where(function ($outer) use ($dismissedBefore) {
                            $outer->where('site_deployments.status', SiteDeployment::STATUS_RUNNING)
                                ->orWhere(function ($terminal) use ($dismissedBefore) {
                                    $terminal->whereIn('site_deployments.status', [
                                        SiteDeployment::STATUS_SUCCESS,
                                        SiteDeployment::STATUS_FAILED,
                                        SiteDeployment::STATUS_SKIPPED,
                                    ])->where(function ($time) use ($dismissedBefore) {
                                        $time->where('site_deployments.finished_at', '>', $dismissedBefore)
                                            ->orWhere(function ($fallback) use ($dismissedBefore) {
                                                $fallback->whereNull('site_deployments.finished_at')
                                                    ->where('site_deployments.created_at', '>', $dismissedBefore);
                                            });
                                    });
                                });
                        });
                    },
                )
                ->orderByDesc('site_deployments.created_at')
                ->limit($recentLimit * 3)
                ->pluck('site_id')
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->take($recentLimit)
                ->values();
        }

        return $runningSiteIds
            ->concat($recentSiteIds)
            ->unique()
            ->take(self::BROWSE_SITE_LIMIT)
            ->values()
            ->all();
    }

    protected function currentOrganizationId(): ?string
    {
        $key = 'deploy_console.current_org_id';
        if (request()->attributes->has($key)) {
            $cached = request()->attributes->get($key);

            return is_string($cached) && $cached !== '' ? $cached : null;
        }

        $orgId = auth()->user()?->currentOrganization()?->id;
        $resolved = $orgId !== null ? (string) $orgId : null;
        request()->attributes->set($key, $resolved ?? '');

        return $resolved;
    }

    protected function dismissedBefore(): ?Carbon
    {
        $orgId = $this->currentOrganizationId();
        if ($orgId === null) {
            return null;
        }

        $raw = session(self::DISMISSED_BEFORE_SESSION_PREFIX.$orgId);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function deploymentIsDismissed(SiteDeployment $deployment, Carbon $dismissedBefore): bool
    {
        if ($deployment->status === SiteDeployment::STATUS_RUNNING) {
            return false;
        }

        if (! in_array($deployment->status, [
            SiteDeployment::STATUS_SUCCESS,
            SiteDeployment::STATUS_FAILED,
            SiteDeployment::STATUS_SKIPPED,
        ], true)) {
            return false;
        }

        $at = $deployment->finished_at ?? $deployment->created_at;

        return Carbon::parse($at)->lessThanOrEqualTo($dismissedBefore);
    }

    protected function forgetRowComputeds(): void
    {
        unset($this->watchedRows, $this->watchedInProgress, $this->finishedHistoryCount);
    }
}
