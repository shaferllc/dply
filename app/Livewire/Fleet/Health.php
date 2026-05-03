<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Fleet-wide health dashboard. Renders the same metrics
 * dply:fleet:doctor reports, scoped to the current org:
 *
 *   - server count (with drift breakdown)
 *   - sites pinned to engines not registered on their server
 *   - sites with runtimes not pre-pinned in server meta
 *   - currently-running deploys (with long-running subset)
 *   - sites whose latest settled deploy failed
 *
 * Each metric links to the corresponding CLI command for terminal
 * follow-up. Read-only — no mutations.
 */
class Health extends Component
{
    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $servers = Server::query()
            ->where('organization_id', $org->id)
            ->with('databaseEngines')
            ->get();
        $serverIds = $servers->pluck('id');

        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->get(['id', 'server_id', 'name', 'slug', 'runtime', 'database_engine']);

        $drift = $this->computeDrift($servers, $sites);
        $deploys = $this->computeDeployHealth($sites);
        $successRate = $this->computeSuccessRate($sites->pluck('id'));
        $mostActive = $this->computeMostActive($sites);

        return view('livewire.fleet.health', [
            'org' => $org,
            'serverCount' => $servers->count(),
            'siteCount' => $sites->count(),
            'successRate' => $successRate,
            'drift' => $drift,
            'deploys' => $deploys,
            'mostActive' => $mostActive,
        ])->layout('layouts.app');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Server>  $servers
     * @param  \Illuminate\Database\Eloquent\Collection<int, Site>  $sites
     * @return array{
     *     servers_with_drift: int,
     *     sites_with_unregistered_engine: list<array{site: string, engine: string, server: string}>,
     *     sites_needing_runtime_install: list<array{site: string, runtime: string, server: string}>
     * }
     */
    private function computeDrift($servers, $sites): array
    {
        $serverEngineKeys = $servers->mapWithKeys(fn (Server $s) => [
            $s->id => $s->databaseEngines->pluck('engine')->all(),
        ]);
        $serverRuntimeKeys = $servers->mapWithKeys(fn (Server $s) => [
            $s->id => array_keys(is_array($s->meta['runtime_defaults'] ?? null) ? $s->meta['runtime_defaults'] : []),
        ]);
        $serverNames = $servers->mapWithKeys(fn (Server $s) => [$s->id => $s->name]);

        $unregisteredEngine = [];
        $needingRuntimeInstall = [];
        $serversWithDrift = [];
        foreach ($sites as $site) {
            $serverDriftKey = false;
            if ($site->database_engine !== null
                && ! in_array($site->database_engine, $serverEngineKeys[$site->server_id] ?? [], true)
            ) {
                $unregisteredEngine[] = [
                    'site' => $site->name,
                    'engine' => $site->database_engine,
                    'server' => $serverNames[$site->server_id] ?? '—',
                ];
                $serverDriftKey = true;
            }
            $runtime = $site->runtime;
            if (
                $runtime !== null
                && ! in_array($runtime, ['php', 'static'], true)
                && ! in_array($runtime, $serverRuntimeKeys[$site->server_id] ?? [], true)
            ) {
                $needingRuntimeInstall[] = [
                    'site' => $site->name,
                    'runtime' => $runtime,
                    'server' => $serverNames[$site->server_id] ?? '—',
                ];
                $serverDriftKey = true;
            }
            if ($serverDriftKey) {
                $serversWithDrift[$site->server_id] = true;
            }
        }

        return [
            'servers_with_drift' => count($serversWithDrift),
            'sites_with_unregistered_engine' => $unregisteredEngine,
            'sites_needing_runtime_install' => $needingRuntimeInstall,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Site>  $sites
     * @return array{
     *     running: int,
     *     long_running: int,
     *     failed_latest: list<array{site: string, deployment_id: string, finished_at: ?string}>
     * }
     */
    private function computeDeployHealth($sites): array
    {
        $siteIds = $sites->pluck('id');

        $running = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->count();

        $longRunning = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->where('started_at', '<', now()->subMinutes(15))
            ->count();

        $failedLatest = [];
        $siteNames = $sites->mapWithKeys(fn (Site $s) => [$s->id => $s->name]);
        foreach ($siteIds as $siteId) {
            $latest = SiteDeployment::query()
                ->where('site_id', $siteId)
                ->whereIn('status', [
                    SiteDeployment::STATUS_SUCCESS,
                    SiteDeployment::STATUS_FAILED,
                    SiteDeployment::STATUS_SKIPPED,
                ])
                ->orderByDesc('started_at')
                ->first(['id', 'status', 'finished_at']);
            if ($latest !== null && $latest->status === SiteDeployment::STATUS_FAILED) {
                $failedLatest[] = [
                    'site' => $siteNames[$siteId] ?? '—',
                    'deployment_id' => $latest->id,
                    'finished_at' => $latest->finished_at?->toIso8601String(),
                ];
            }
        }

        return [
            'running' => $running,
            'long_running' => $longRunning,
            'failed_latest' => $failedLatest,
        ];
    }

    /**
     * Deploy success rate over the last 7 days. Excludes skipped
     * deploys (idempotency conflicts) since they're not really
     * "tries" — a skipped deploy means another one was running.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $siteIds
     * @return array{percent: ?int, total: int, success: int, failed: int, window_days: int}
     */
    private function computeSuccessRate($siteIds): array
    {
        $windowDays = 7;
        $since = now()->subDays($windowDays);

        $base = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('started_at', '>=', $since)
            ->whereIn('status', [
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_FAILED,
            ]);

        $total = (clone $base)->count();
        $success = (clone $base)->where('status', SiteDeployment::STATUS_SUCCESS)->count();
        $failed = $total - $success;

        return [
            'percent' => $total > 0 ? (int) round($success / $total * 100) : null,
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'window_days' => $windowDays,
        ];
    }

    /**
     * Top 5 most-deployed sites in the last 30 days.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Site>  $sites
     * @return list<array{site: Site, count: int, server_id: ?string}>
     */
    private function computeMostActive($sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        $since = now()->subDays(30);
        $counts = SiteDeployment::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->where('started_at', '>=', $since)
            ->whereIn('status', [
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_FAILED,
            ])
            ->selectRaw('site_id, COUNT(*) as deploy_count')
            ->groupBy('site_id')
            ->orderByDesc('deploy_count')
            ->limit(5)
            ->get(['site_id', 'deploy_count']);

        $rows = [];
        foreach ($counts as $row) {
            $site = $sites->firstWhere('id', $row->site_id);
            if ($site === null) {
                continue;
            }
            $rows[] = [
                'site' => $site,
                'count' => (int) $row->deploy_count,
                'server_id' => $site->server_id,
            ];
        }

        return $rows;
    }
}
