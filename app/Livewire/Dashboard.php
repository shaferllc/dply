<?php

namespace App\Livewire;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Insights\Services\OrganizationInsightsMetricsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /** Free-text filter over server name and IP. */
    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** Row filter: `all` or `attention`. */
    #[Url(as: 'show', except: 'all')]
    public string $filter = 'all';

    /** Reset both filters — the empty-state escape hatch. */
    public function clearFilters(): void
    {
        $this->q = '';
        $this->filter = 'all';
    }

    public function render(OrganizationInsightsMetricsService $insightsMetrics): View
    {
        $user = auth()->user();
        $org = $user->currentOrganization();

        // Edge / serverless / Cloud placeholder hosts are product-line
        // inventory, not the BYO Servers fleet — keep this table aligned with
        // /servers.
        $serverQuery = $user->servers()->onlyMachineHosts();
        $serverCount = (clone $serverQuery)->count();

        $servers = (clone $serverQuery)
            ->when($this->q !== '', function ($query): void {
                $term = '%'.trim($this->q).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'ilike', $term)
                        ->orWhere('ip_address', 'ilike', $term);
                });
            })
            ->withCount('sites')
            ->get();

        $rows = $this->fleetRows($servers, $insightsMetrics);
        $attentionCount = $rows->where('attention', true)->count();

        $orgInsights = $insightsMetrics->organizationSummary($org);
        $hasProviderCredentials = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->exists()
            : false;

        // Hoisted so the banner, the health sparkline and the deploy strip all
        // share one pair of id lookups instead of repeating them.
        $orgServerIds = $org ? $org->serverIds() : collect();
        $orgSiteIds = $orgServerIds->isEmpty()
            ? collect()
            : Site::query()->whereIn('server_id', $orgServerIds)->pluck('id');

        $healthAlert = $org ? $this->computeHealthAlert($orgServerIds, $orgSiteIds) : null;

        return view('livewire.dashboard', [
            'organization' => $org,
            'rows' => $this->filter === 'attention' ? $rows->where('attention', true)->values() : $rows,
            'serverCount' => $serverCount,
            // Both chip counts are scoped to the current search, so they always
            // add up against each other rather than against the whole fleet.
            'matchedCount' => $rows->count(),
            'attentionCount' => $attentionCount,
            'orgInsights' => $orgInsights,
            'hasProviderCredentials' => $hasProviderCredentials,
            'healthAlert' => $healthAlert,
            'healthSeries' => $insightsMetrics->dailyHealthSeries($orgServerIds),
            'deployOutcomes' => $this->recentDeployOutcomes($orgSiteIds),
        ]);
    }

    /**
     * One row per server: the box plus the three signals the table sorts on —
     * latest health score, open findings, and how the last deploy ended.
     *
     * Worst first: a failed deploy outranks a critical finding, which outranks
     * volume, and health breaks the tie.
     *
     * Each row: server, health, open/worst findings, last deploy, and the
     * derived `attention` flag and `sort` weight the table orders on.
     *
     * @param  iterable<int, Server>  $servers
     */
    private function fleetRows(iterable $servers, OrganizationInsightsMetricsService $insightsMetrics): Collection
    {
        $servers = collect($servers);
        $serverIds = $servers->pluck('id');

        $findings = $insightsMetrics->perServerRollup($serverIds);
        $health = $insightsMetrics->latestHealthScores($serverIds);
        $deploys = $this->latestDeployPerServer($serverIds);

        $rank = ['critical' => 3, 'warning' => 2, 'info' => 1];

        return $servers
            ->map(function (Server $server) use ($findings, $health, $deploys, $rank): array {
                $open = (int) ($findings[$server->id]['open'] ?? 0);
                $worst = $findings[$server->id]['worst'] ?? null;
                $deploy = $deploys[$server->id] ?? null;
                $failed = ($deploy['status'] ?? null) === SiteDeployment::STATUS_FAILED;
                $score = $health->get($server->id);

                return [
                    'server' => $server,
                    'health' => $score === null ? null : (int) round((float) $score),
                    'open' => $open,
                    'worst' => $worst,
                    'deploy_status' => $deploy['status'] ?? null,
                    'deploy_at' => $deploy['at'] ?? null,
                    'attention' => $open > 0 || $failed,
                    'sort' => ($failed ? 4000 : 0) + (($rank[$worst] ?? 0) * 1000) + min($open, 999),
                ];
            })
            ->sortBy(fn (array $row): array => [
                -$row['sort'],
                $row['health'] ?? 101,
                (string) $row['server']->name,
            ])
            ->values();
    }

    /**
     * Latest deploy per server, keyed by server id.
     *
     * Joined through `sites` rather than `site_deployments.server_id`: that
     * column is nullable and deliberately never backfilled, so the site's
     * current server is the only link that covers historical rows.
     *
     * Values are `['status' => string, 'at' => Carbon|null]`.
     *
     * @param  Collection<int, string>  $serverIds
     */
    private function latestDeployPerServer(Collection $serverIds): Collection
    {
        if ($serverIds->isEmpty()) {
            return collect();
        }

        $rows = DB::table('site_deployments')
            ->join('sites', 'sites.id', '=', 'site_deployments.site_id')
            ->whereIn('sites.server_id', $serverIds)
            ->selectRaw('distinct on (sites.server_id) sites.server_id, site_deployments.status, site_deployments.created_at')
            ->orderBy('sites.server_id')
            ->orderByDesc('site_deployments.created_at')
            ->get();

        return collect($rows)
            ->keyBy('server_id')
            ->map(fn ($row): array => [
                'status' => (string) $row->status,
                'at' => $row->created_at ? Carbon::parse($row->created_at) : null,
            ]);
    }

    /**
     * Outcomes of the last $limit settled deploys across the workspace, oldest
     * first, for the dashboard's deploy strip.
     *
     * A rolling count rather than a calendar window on purpose: a workspace
     * that has not deployed in months is exactly the one whose deploy history
     * you want to see, and "last 30 days" would show it nothing.
     *
     * @param  Collection<int, string>  $siteIds
     * @return Collection<int, string>
     */
    private function recentDeployOutcomes(Collection $siteIds, int $limit = 20): Collection
    {
        if ($siteIds->isEmpty()) {
            return collect();
        }

        return SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->whereIn('status', [
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_FAILED,
                SiteDeployment::STATUS_SKIPPED,
            ])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('status')
            ->reverse()
            ->values();
    }

    /**
     * Snapshot of fleet trouble for the dashboard banner. Returns null
     * when nothing's wrong (banner stays hidden); otherwise returns
     * counts that the view turns into a sentence.
     *
     * @param  Collection<int, string>  $serverIds
     * @param  Collection<int, string>  $siteIds
     * @return array{
     *     failed_latest: int,
     *     long_running: int,
     *     drift_servers: int
     * }|null
     */
    private function computeHealthAlert(Collection $serverIds, Collection $siteIds): ?array
    {
        if ($serverIds->isEmpty()) {
            return null;
        }

        $longRunning = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->where('started_at', '<', now()->subMinutes(15))
            ->count();

        // Failed-latest: count of sites whose most recent settled deploy failed.
        //
        // This used to run one "latest settled deploy" query per site, so the
        // dashboard's query count grew with the fleet. Postgres DISTINCT ON does
        // the same latest-row-per-group pick in a single statement, and the
        // `order by site_id, started_at desc` reproduces the old
        // orderByDesc()->first() exactly — including Postgres's NULLS FIRST on a
        // DESC sort, so a deploy with no started_at still wins its site.
        $latestSettledPerSite = SiteDeployment::query()
            ->selectRaw('distinct on (site_id) site_id, status')
            ->whereIn('site_id', $siteIds)
            ->whereIn('status', [
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_FAILED,
                SiteDeployment::STATUS_SKIPPED,
            ])
            ->orderBy('site_id')
            ->orderByDesc('started_at');

        $failedLatest = DB::query()
            ->fromSub($latestSettledPerSite, 'latest_settled')
            ->where('status', SiteDeployment::STATUS_FAILED)
            ->count();

        // Drift: cheap signal — sites pinned to engines not on their server.
        //
        // Also collapsed from "fetch every server + its engines, then one exists()
        // per server" to a single NOT EXISTS. Servers with no registered engines
        // still count as drift, matching the old whereNotIn() against an empty
        // list (which Laravel compiles to a tautology).
        $driftServers = Site::query()
            ->whereIn('server_id', $serverIds)
            ->whereNotNull('database_engine')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('server_database_engines')
                    ->whereColumn('server_database_engines.server_id', 'sites.server_id')
                    ->whereColumn('server_database_engines.engine', 'sites.database_engine');
            })
            ->distinct()
            ->count('server_id');

        if ($failedLatest === 0 && $longRunning === 0 && $driftServers === 0) {
            return null;
        }

        return [
            'failed_latest' => $failedLatest,
            'long_running' => $longRunning,
            'drift_servers' => $driftServers,
        ];
    }
}
