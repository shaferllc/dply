<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Build live per-site rows for a deploy console: each site with its latest
 * deployment, phase timeline, and in-flight state. Shared by the per-site deploy
 * sidebar ({@see \App\Livewire\Sites\DeployControl}) and the fleet deploy console
 * ({@see \App\Livewire\DeployConsoleSidebar}), so a deploy looks identical wherever
 * it's watched.
 *
 * Performance: latest deployments are bulk-fetched (no N+1). Full phase timelines
 * (deploySteps / bindings / hooks + phase_results JSON) load only for in-progress
 * or failed runs — finished successes stay lightweight for snappy sidebar open.
 */
class DeployConsoleRows
{
    /**
     * @param  array<int|string, mixed>  $siteIds  watched sites, order preserved
     * @param  string|null  $selfId  site id to flag as `is_self` (the page's own site, if any)
     * @return list<array<string, mixed>>
     */
    public static function forSiteIds(array $siteIds, ?string $selfId = null): array
    {
        $orderedIds = [];
        foreach ($siteIds as $id) {
            $key = (string) $id;
            if ($key !== '' && ! in_array($key, $orderedIds, true)) {
                $orderedIds[] = $key;
            }
        }

        if ($orderedIds === []) {
            return [];
        }

        $sites = Site::query()
            ->whereIn('id', $orderedIds)
            ->with('server:id,name,ip_address,meta')
            ->get([
                'id',
                'name',
                'server_id',
                'git_branch',
                'git_repository_url',
                'runtime',
                'meta',
            ])
            ->keyBy(fn (Site $s): string => (string) $s->id);

        /** @var Collection<string, SiteDeployment> $latestBySite */
        $latestBySite = self::latestDeploymentsForSites($orderedIds);

        // First pass: resolve lock / in-progress without building timelines.
        $needsTimeline = [];
        $locks = [];
        foreach ($orderedIds as $id) {
            $peer = $sites->get($id);
            if ($peer === null) {
                continue;
            }

            $latest = $latestBySite->get($id);
            $lock = Cache::get('site-deploy-active:'.$peer->id);
            $locks[$id] = $lock;
            $lockStarted = ($lock && ! empty($lock['started_at'])) ? Carbon::parse($lock['started_at']) : null;

            $lockOutranksLatest = $lock !== null && (
                $latest === null
                || (
                    $latest->status !== SiteDeployment::STATUS_RUNNING
                    && (
                        $latest->finished_at === null
                        || $lockStarted === null
                        || $lockStarted->greaterThanOrEqualTo($latest->finished_at)
                    )
                )
            );

            if (! $lockOutranksLatest && $latest !== null && (
                $latest->status === SiteDeployment::STATUS_RUNNING
                || $latest->status === SiteDeployment::STATUS_FAILED
            )) {
                $needsTimeline[] = (string) $latest->id;
            }
        }

        // Hydrate heavy columns only for runs that render an expanded timeline.
        if ($needsTimeline !== []) {
            self::hydrateDeploymentPayloads($latestBySite, $needsTimeline);
            $timelineSiteIds = [];
            foreach ($orderedIds as $id) {
                $deployment = $latestBySite->get($id);
                if ($deployment !== null && in_array((string) $deployment->id, $needsTimeline, true)) {
                    $timelineSiteIds[] = $id;
                }
            }
            $timelineSites = $sites->only($timelineSiteIds);
            if ($timelineSites->isNotEmpty()) {
                $timelineSites->load(['deploySteps', 'bindings', 'deployHooks.anchorStep']);
            }
        }

        $rows = [];
        foreach ($orderedIds as $id) {
            $peer = $sites->get($id);
            if ($peer === null) {
                continue;
            }

            $latest = $latestBySite->get($id);
            $lock = $locks[$id] ?? null;
            $lockStarted = ($lock && ! empty($lock['started_at'])) ? Carbon::parse($lock['started_at']) : null;

            $lockOutranksLatest = $lock !== null && (
                $latest === null
                || (
                    $latest->status !== SiteDeployment::STATUS_RUNNING
                    && (
                        $latest->finished_at === null
                        || $lockStarted === null
                        || $lockStarted->greaterThanOrEqualTo($latest->finished_at)
                    )
                )
            );

            $startingFresh = $lockOutranksLatest;
            $buildTimeline = ! $startingFresh
                && $latest !== null
                && in_array((string) $latest->id, $needsTimeline, true);

            $phases = $buildTimeline
                ? SiteDeployTimeline::forDeployment($peer, $latest)
                : [];

            $done = 0;
            $current = null;
            foreach ($phases as $p) {
                if (in_array($p['status'], ['success', 'skipped'], true)) {
                    $done++;
                } elseif ($p['status'] === 'running' && $current === null) {
                    $current = $p['label'];
                }
            }

            // Every phase has reached a terminal good state (success/skipped) =
            // the deploy is visibly finished. The worker writes phase_results
            // before it flips the deployment row to success + sets finished_at,
            // so without this the "Starting" label and spinner linger for that
            // whole window (and the lock's 10-min TTL). Treat all-phases-done as
            // done NOW so the card clears as soon as the work is.
            $phasesComplete = $phases !== [] && $done === count($phases);

            $running = ! $startingFresh && ! $phasesComplete && $latest?->status === SiteDeployment::STATUS_RUNNING;
            $inProgress = $running || ($startingFresh && $lockStarted !== null && $lockStarted->greaterThan(now()->subSeconds(90)));

            // Deploy context: site/server/branch are always cheap. SHA + provider
            // commit URL come from the *current* deployment only — never the
            // previous finished run while a fresh kickoff is still locking.
            $activeDeployment = $startingFresh ? null : $latest;
            $gitSha = filled($activeDeployment?->git_sha) ? (string) $activeDeployment->git_sha : null;
            $branch = trim((string) ($peer->git_branch ?? ''));
            $serverIp = trim((string) ($peer->server->ip_address ?? ''));

            $rows[] = [
                'id' => (string) $peer->id,
                'name' => $peer->name,
                'server' => $peer->server?->name,
                'server_id' => $peer->server_id !== null ? (string) $peer->server_id : null,
                'server_ip' => $serverIp !== '' ? $serverIp : null,
                'branch' => $branch !== '' ? $branch : null,
                'git_sha' => $gitSha,
                'short_sha' => $gitSha !== null ? substr($gitSha, 0, 7) : null,
                // Pure string parse from git_repository_url — no extra queries.
                'commit_url' => $gitSha !== null ? $peer->commitWebUrl($gitSha) : null,
                'is_self' => $selfId !== null && (string) $peer->id === $selfId,
                'is_worker' => $peer->isWorkerSite(),
                'latest' => $activeDeployment,
                'status' => match (true) {
                    $startingFresh => 'starting',
                    // All phases done — show the recorded terminal status, or
                    // 'success' if the row hasn't been finalised yet.
                    $phasesComplete => $latest->status !== SiteDeployment::STATUS_RUNNING
                        ? $latest->status
                        : 'success',
                    default => $latest->status ?? 'queued',
                },
                'phases' => $phases,
                'phase_done' => $done,
                'phase_total' => count($phases),
                'current_phase' => $current,
                'in_progress' => $inProgress,
                'starting_fresh' => $startingFresh,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function anyInProgress(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['in_progress']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Latest deployment per site in two queries: MAX(id) group + light column fetch.
     * ULIDs are time-ordered, matching deployments() orderByDesc('id').
     *
     * @param  list<string>  $siteIds
     * @return Collection<string, SiteDeployment>
     */
    protected static function latestDeploymentsForSites(array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }

        $latestIds = SiteDeployment::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('site_id', $siteIds)
            ->groupBy('site_id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return collect();
        }

        return SiteDeployment::query()
            ->whereIn('id', $latestIds)
            ->get([
                'id',
                'site_id',
                'status',
                'git_sha',
                'started_at',
                'finished_at',
                'created_at',
            ])
            ->keyBy(fn (SiteDeployment $d): string => (string) $d->site_id);
    }

    /**
     * Attach phase_results (+ log_output for failures) onto already-loaded light models.
     *
     * @param  Collection<string, SiteDeployment>  $latestBySite
     * @param  list<string>  $deploymentIds
     */
    protected static function hydrateDeploymentPayloads(Collection $latestBySite, array $deploymentIds): void
    {
        $payloads = SiteDeployment::query()
            ->whereIn('id', $deploymentIds)
            ->get(['id', 'phase_results', 'log_output'])
            ->keyBy(fn (SiteDeployment $d): string => (string) $d->id);

        foreach ($latestBySite as $deployment) {
            $heavy = $payloads->get((string) $deployment->id);
            if ($heavy === null) {
                continue;
            }
            $deployment->setAttribute('phase_results', $heavy->phase_results);
            $deployment->setAttribute('log_output', $heavy->log_output);
            $deployment->syncOriginal();
        }
    }
}
