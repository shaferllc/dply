<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Build live per-site rows for a deploy console: each site with its latest
 * deployment, phase timeline, and in-flight state. Shared by the per-site deploy
 * sidebar ({@see \App\Livewire\Sites\DeployControl}) and the fleet deploy console
 * ({@see \App\Livewire\Servers\Index}), so a deploy looks identical wherever it's
 * watched.
 */
class DeployConsoleRows
{
    /**
     * @param  array<string, mixed> $siteIds  watched sites, order preserved
     * @param  string|null  $selfId  site id to flag as `is_self` (the page's own site, if any)
     * @return list<array<string, mixed>>
     */
    public static function forSiteIds(array $siteIds, ?string $selfId = null): array
    {
        if ($siteIds === []) {
            return [];
        }

        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->with('server:id,name')
            ->get()
            ->keyBy(fn (Site $s): string => (string) $s->id);

        $rows = [];
        foreach ($siteIds as $id) {
            $peer = $sites->get($id);
            if ($peer === null) {
                continue;
            }

            $latest = $peer->deployments()->latest()->first();
            $lock = Cache::get('site-deploy-active:'.$peer->id);
            $lockStarted = ($lock && ! empty($lock['started_at'])) ? Carbon::parse($lock['started_at']) : null;

            $phases = $latest ? SiteDeployTimeline::forDeployment($peer, $latest) : [];
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

            // Just-queued but the worker hasn't recorded a running deployment yet.
            $startingFresh = ! $phasesComplete && $lock !== null && (
                $latest === null
                || ($latest->status !== SiteDeployment::STATUS_RUNNING
                    && ($latest->finished_at === null || $lockStarted === null || $lockStarted->greaterThanOrEqualTo($latest->finished_at)))
            );
            $running = ! $phasesComplete && $latest?->status === SiteDeployment::STATUS_RUNNING;
            $inProgress = $running || ($startingFresh && $lockStarted !== null && $lockStarted->greaterThan(now()->subSeconds(90)));

            $rows[] = [
                'id' => (string) $peer->id,
                'name' => $peer->name,
                'server' => $peer->server?->name,
                'is_self' => $selfId !== null && (string) $peer->id === $selfId,
                'is_worker' => $peer->isWorkerSite(),
                'latest' => $latest,
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
}
