<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\PollDoksClusterStatusJob;
use App\Jobs\PollEksClusterStatusJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\Concerns\BuildsContainerLaunchSummary;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCacheService;
use App\Models\ServerDatabaseBackup;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteFileBackup;
use App\Models\SupervisorProgram;
use App\Services\Servers\ServerCostCard;
use App\Services\Servers\ServerHealthCockpit;
use App\Services\Servers\ServerPatchAdvisor;
use App\Services\Servers\ServerReleaseHygiene;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\DatabaseEngineInfo;
use App\Support\Servers\InstalledStack;
use App\Support\Servers\SharedHostReport;
use App\Support\Servers\SupervisorQueueProgramTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

/**
 * Server workspace landing page — at-a-glance dashboard.
 *
 * Job is "is anything broken? what's running here?" Operator scans the
 * tiles and click-through cards, then drills into the dedicated sub-page
 * (sites, databases, monitor, deploys, insights) for detail.
 *
 * Heavy content lives on the dedicated sub-pages — this component is
 * intentionally thin. If you find yourself adding a 100-line panel here,
 * it probably belongs on the matching workspace nav entry instead.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceOverview extends Component
{
    use RendersWorkspacePlaceholder;
    use BuildsContainerLaunchSummary;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public function mount(Server $server): mixed
    {
        $this->bootWorkspace($server);

        // A serverless function is not a server — the DO Functions namespace
        // host is an implementation detail. Redirect to the function
        // workspace so the operator never sees server-shaped chrome (SSH,
        // setup, metrics) that does not apply to a function.
        if ($server->isDigitalOceanFunctionsHost()) {
            $function = $server->sites()->orderBy('created_at')->first();
            if ($function !== null) {
                return $this->redirect(
                    route('sites.show', ['server' => $server, 'site' => $function]),
                );
            }
        }

        // Same story for dply Edge + dply Cloud synthetic hosts — neither
        // has a VM, SSH, metrics, or anything else a "server overview"
        // would surface. Redirect to the first site's workspace (single
        // app per synthetic server in the common case) or fall back to
        // the product index when this server has no sites yet.
        if ($server->isDplyEdgeHost() || $server->isDplyCloudHost()) {
            $site = $server->sites()->orderBy('created_at')->first();
            if ($site !== null) {
                return $this->redirect(
                    route('sites.show', ['server' => $server, 'site' => $site]),
                );
            }

            return $this->redirect(
                $server->isDplyEdgeHost() ? route('edge.index') : route('cloud.index'),
            );
        }

        $this->kickClusterPollIfStale();

        return null;
    }

    /**
     * Manual "re-check the cluster at the provider" — surfaced as the retry
     * button on the K8s error banner below. Mirrors WorkspaceCluster::retryPolling
     * so operators landing on overview after a manual cluster deletion can
     * trigger a fresh poll without bouncing to the dedicated cluster page.
     */
    public function retryClusterPolling(): void
    {
        $this->authorize('update', $this->server);

        $provider = (string) ($this->server->meta['kubernetes']['provider'] ?? 'digitalocean');
        if ($provider === 'aws') {
            PollEksClusterStatusJob::dispatch($this->server);
        } else {
            PollDoksClusterStatusJob::dispatch($this->server);
        }
        $this->toastSuccess(__('Re-checking cluster status…'));
    }

    public function rerunSetup(): void
    {
        $this->authorize('update', $this->server);

        $server = $this->server->fresh();
        if (! $server || ! RunSetupScriptJob::shouldDispatch($server)) {
            $this->toastError('This server is not ready for a setup re-run yet.');

            return;
        }

        $meta = $server->meta ?? [];
        unset($meta['provision_task_id']);
        unset($meta['provision_step_snapshots']);

        $server->update([
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'meta' => $meta,
        ]);

        $fresh = $server->fresh();
        WaitForServerSshReadyJob::dispatch($fresh ?? $server);

        $this->redirectRoute('servers.journey', $server, navigate: true);
    }

    public function render(): View
    {
        // No $this->server->refresh() here: Livewire re-resolves the bound
        // model from the database on every request (route binding on first
        // load, the Eloquent synthesizer on later updates), so the row is
        // already current at render time. mount() already re-pulls via
        // kickClusterPollIfStale() when a sync cluster poll mutates it, so
        // refreshing again here only doubled the `select * from servers`.
        $sites = $this->server->sites()->get(['id', 'status']);
        $siteIds = $sites->pluck('id');

        $latestDeployment = $siteIds->isEmpty()
            ? null
            : SiteDeployment::query()
                ->with('site:id,name,server_id')
                ->whereIn('site_id', $siteIds)
                ->latest('created_at')
                ->first();

        $databaseSummary = [
            'count' => $this->server->serverDatabases()->count(),
            // 'errors' field intended for a future health probe; leaving the
            // shape stable so the view can always pull a 'X errors' badge.
            'errors' => 0,
        ];

        $openInsightsCount = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->count();

        $criticalInsightsCount = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->where('severity', InsightFinding::SEVERITY_CRITICAL)
            ->count();

        $deployingCount = $sites
            ->whereIn('status', ['deploying', 'queued'])
            ->count();

        $healthSummary = [
            'status' => $this->server->health_status,
            'last_checked_at' => $this->server->last_health_check_at,
        ];

        $currentUser = Auth::user();
        $hasProfileSshKeys = $currentUser?->sshKeys()->exists() ?? false;
        $serverHasPersonalProfileKey = $this->server->hasPersonalUserSshKey($currentUser);

        $notificationSummary = [
            'channel_count' => $this->server->notificationSubscriptions()->distinct('notification_channel_id')->count('notification_channel_id'),
            'manage_url' => $this->server->organization_id
                ? route('profile.notification-channels.bulk-assign', ['server' => $this->server->id])
                : null,
        ];

        // Background tile: surface active worker + schedule + backup health on the high-traffic
        // Overview page so operators see drift (failed backups, paused schedules, stopped workers)
        // without having to drill into the Background subpages individually.
        $weekAgo = now()->subDays(7);
        $backgroundSummary = [
            'active_workers' => SupervisorProgram::query()
                ->where('server_id', $this->server->id)
                ->whereIn('program_type', SupervisorQueueProgramTypes::TYPES)
                ->where('is_active', true)
                ->count(),
            'active_schedules' => ServerBackupSchedule::query()
                ->where('server_id', $this->server->id)
                ->where('is_active', true)
                ->count(),
            'paused_schedules' => ServerBackupSchedule::query()
                ->where('server_id', $this->server->id)
                ->where('is_active', false)
                ->count(),
            'failed_backups_7d' => (int) ServerDatabaseBackup::query()
                ->whereIn('server_database_id', $this->server->serverDatabases()->pluck('id'))
                ->where('status', 'failed')
                ->where('created_at', '>=', $weekAgo)
                ->count()
                + (int) SiteFileBackup::query()
                    ->whereIn('site_id', $this->server->sites()->pluck('id'))
                    ->where('status', 'failed')
                    ->where('created_at', '>=', $weekAgo)
                    ->count(),
        ];

        // Most-recent metric snapshot for the live CPU/Mem/Disk/Load card.
        // One row, cheap. Same source the Monitor page uses — duplicating it
        // here means the overview reflects current load without a full
        // Monitor-tab fetch and the operator can decide whether to drill in.
        // Read through the memoized relation so the cost card, health cockpit,
        // and billing tier below all reuse this single lookup.
        $latestMetricSnapshot = $this->server->latestMetricSnapshot;

        // Sites preview for the overview. We already have $sites with id+status;
        // pull the small bit extra we need to render five rows (name + updated_at)
        // and the most recent deployment per site so each preview row can show
        // "last deploy: 3m ago". Cap at 5 — the dedicated Sites tab owns the
        // full list.
        $sitesPreview = $this->server->sites()
            ->select(['id', 'name', 'status', 'server_id', 'updated_at'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
        $sitesPreviewLatestDeploys = $sitesPreview->isEmpty()
            ? collect()
            : SiteDeployment::query()
                ->whereIn('site_id', $sitesPreview->pluck('id'))
                ->select(['id', 'site_id', 'status', 'created_at', 'finished_at'])
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('site_id')
                ->map(fn ($group) => $group->first());

        // Onboarding checklist for a fresh server. Each step reads from
        // already-computed state above (no extra queries), and a step is
        // marked done when we can detect the artifact in the database.
        // The checklist hides itself once every applicable step is done.
        //
        // Container hosts (Docker / K8s) skip the SSH-key / monitor-agent /
        // backups steps because those don't apply to clusters — sites are
        // deployed *into* the cluster, not onto an OS the operator manages.
        $hostKind = $this->server->meta['host_kind'] ?? Server::HOST_KIND_VM;
        $isContainerHostForChecklist = in_array(
            $hostKind,
            [Server::HOST_KIND_DOCKER, Server::HOST_KIND_KUBERNETES],
            true,
        );
        $serverRole = (string) ($this->server->meta['server_role'] ?? '');
        $isCacheRoleHost = in_array($serverRole, ['redis', 'valkey'], true);
        $isDatabaseRoleHost = $serverRole === 'database';
        $isWorkerRoleHost = $serverRole === 'worker';
        // Dedicated cache/db boxes never host site code, so their site/stack/deploy
        // cards are hidden. A worker IS an app host (it runs queue workers from the
        // deployed code), so it keeps sites/deploys — it just doesn't serve web traffic.
        $isDedicatedServiceRoleHost = $isCacheRoleHost || $isDatabaseRoleHost;
        $monitorInstalled = $latestMetricSnapshot !== null
            && is_array($latestMetricSnapshot->payload ?? null)
            && isset($latestMetricSnapshot->payload['cpu_pct']);
        $hasBackupSchedule = ($backgroundSummary['active_schedules'] ?? 0)
            + ($backgroundSummary['paused_schedules'] ?? 0) > 0;
        // An "installed" cache engine is one that's past the install pipeline
        // (running or stopped). Pending/installing/uninstalling/failed rows
        // are mid-flight and don't satisfy the onboarding step yet.
        $cacheRows = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->whereIn('status', [
                ServerCacheService::STATUS_RUNNING,
                ServerCacheService::STATUS_STOPPED,
            ])
            ->get();
        $cacheEngineInstalled = $cacheRows->isNotEmpty();

        // Tile pack for the cache-role Overview (server_role redis/valkey). Pulls
        // a short-TTL INFO snapshot from the highest-priority running redis-family
        // engine — most boxes have exactly one. Returns null when this isn't a
        // cache-role host so the view falls through to the generic tiles.
        $cacheTileData = null;
        $cacheTileEngine = null;
        if ($isCacheRoleHost) {
            $cacheTileRow = $cacheRows
                ->filter(fn ($row) => in_array($row->engine, ['redis', 'valkey', 'keydb', 'dragonfly'], true))
                ->sortByDesc(fn ($row) => $row->status === ServerCacheService::STATUS_RUNNING ? 1 : 0)
                ->first();
            if ($cacheTileRow !== null) {
                $cacheTileData = app(CacheServiceStats::class)
                    ->overviewSnapshot($this->server, $cacheTileRow);
                $cacheTileEngine = $cacheTileRow->engine;
            }
        }

        // Tile pack for database-role Overview (server_role database). Uses
        // control-plane rows only — no SSH probe — so the page stays fast
        // and works before the engine finishes installing.
        $databaseTileData = null;
        if ($isDatabaseRoleHost) {
            $installedStack = InstalledStack::fromMeta($this->server);
            $engineRows = ServerDatabaseEngine::query()
                ->where('server_id', $this->server->id)
                ->whereIn('status', [
                    ServerDatabaseEngine::STATUS_RUNNING,
                    ServerDatabaseEngine::STATUS_STOPPED,
                    ServerDatabaseEngine::STATUS_INSTALLING,
                    ServerDatabaseEngine::STATUS_PENDING,
                ])
                ->get();
            $engineRow = $engineRows
                ->sortByDesc(fn (ServerDatabaseEngine $row) => $row->status === ServerDatabaseEngine::STATUS_RUNNING ? 1 : 0)
                ->first();
            $engineKey = $engineRow?->engine
                ?? (is_string($installedStack->database) && $installedStack->database !== 'none'
                    ? strtolower((string) preg_replace('/\d+$/', '', $installedStack->database))
                    : null);
            $engineLabel = $engineKey !== null
                ? (DatabaseEngineInfo::for($engineKey)['label'] ?? ucfirst($engineKey))
                : __('Database engine');
            $databaseTileData = [
                'engine' => $engineKey,
                'engine_label' => $engineLabel,
                'version' => $engineRow?->version ?? $installedStack->databaseVersion,
                'status' => $engineRow?->status,
                'database_count' => $databaseSummary['count'],
                'active_schedules' => $backgroundSummary['active_schedules'],
                'paused_schedules' => $backgroundSummary['paused_schedules'],
                'failed_backups_7d' => $backgroundSummary['failed_backups_7d'],
            ];
        }
        $databaseEngineInstalled = $isDatabaseRoleHost
            ? ServerDatabaseEngine::query()
                ->where('server_id', $this->server->id)
                ->whereIn('status', [
                    ServerDatabaseEngine::STATUS_RUNNING,
                    ServerDatabaseEngine::STATUS_STOPPED,
                ])
                ->exists()
            : false;

        $onboardingSteps = [];
        if (! $isContainerHostForChecklist) {
            $onboardingSteps[] = [
                'key' => 'ssh_key',
                'label' => __('Attach your personal SSH key'),
                'help' => __('So your laptop can log in over SSH directly.'),
                'done' => $serverHasPersonalProfileKey,
                'cta_label' => __('Add key'),
                'cta_route' => route('servers.ssh-keys', $this->server),
            ];
        }
        if ($isCacheRoleHost) {
            // Cache-role servers (server_role redis/valkey) don't host sites —
            // the "first useful thing" is installing the cache engine via the
            // Caches workspace, not connecting a Git repo.
            $engineLabel = $serverRole === 'valkey' ? __('Valkey') : __('Redis');
            $onboardingSteps[] = [
                'key' => 'first_cache_engine',
                'label' => __('Install :engine', ['engine' => $engineLabel]),
                'help' => __('Provision the cache engine apt + systemd on this host.'),
                'done' => $cacheEngineInstalled,
                'cta_label' => __('Open Caches'),
                'cta_route' => route('servers.caches', $this->server),
            ];
        } elseif ($isDatabaseRoleHost) {
            $engineLabel = $databaseTileData['engine_label'] ?? __('Database engine');
            $onboardingSteps[] = [
                'key' => 'first_database_engine',
                'label' => __('Install :engine', ['engine' => $engineLabel]),
                'help' => __('Provision the database engine apt + systemd on this host.'),
                'done' => $databaseEngineInstalled,
                'cta_label' => __('Open Database'),
                'cta_route' => route('servers.databases', $this->server),
            ];
        } else {
            $onboardingSteps[] = [
                'key' => 'first_site',
                'label' => $isContainerHostForChecklist
                    ? __('Add your first container app')
                    : __('Add your first site'),
                'help' => $isContainerHostForChecklist
                    ? __('Point dply at a Git repo and deploy a container.')
                    : __('Connect a Git repo, configure the web root, and deploy.'),
                'done' => $sites->count() > 0,
                'cta_label' => __('Add'),
                'cta_route' => route('sites.create', $this->server),
            ];
        }
        if ($isWorkerRoleHost) {
            // A worker runs queue workers from the deployed code — once the site
            // is on the box, the next step is starting a worker on the Workers tab.
            $onboardingSteps[] = [
                'key' => 'first_worker',
                'label' => __('Start a queue worker'),
                'help' => __('Run your queue workers and scheduled jobs from the Workers tab.'),
                'done' => ($backgroundSummary['active_workers'] ?? 0) > 0,
                'cta_label' => __('Open Workers'),
                'cta_route' => route('servers.workers', $this->server),
            ];
        }
        if (! $isContainerHostForChecklist) {
            $onboardingSteps[] = [
                'key' => 'monitor',
                'label' => __('Install the monitor agent'),
                'help' => __('Streams CPU / memory / disk for the Live load card and Insights.'),
                'done' => $monitorInstalled,
                'cta_label' => __('Install'),
                'cta_route' => route('servers.monitor', $this->server),
            ];
            // Backups workspace is gated by mysql/postgres install tags AND
            // hidden from the Redis sidebar via role_nav_keys — skip the step
            // entirely on cache-role hosts so the checklist doesn't dangle on
            // a CTA route that 404s.
            if (! $isCacheRoleHost && ! $isWorkerRoleHost) {
                $onboardingSteps[] = [
                    'key' => 'backups',
                    'label' => __('Schedule backups'),
                    'help' => __('Automatic database + site-files backups on a cron you choose.'),
                    'done' => $hasBackupSchedule,
                    'cta_label' => __('Open Backups'),
                    'cta_route' => route('servers.backups', $this->server),
                ];
            }
        }
        if ($notificationSummary['manage_url']) {
            $onboardingSteps[] = [
                'key' => 'notifications',
                'label' => __('Hook up notifications'),
                'help' => __('Get pinged on Slack / email when something this server runs misbehaves.'),
                'done' => ($notificationSummary['channel_count'] ?? 0) > 0,
                'cta_label' => __('Manage'),
                'cta_route' => $notificationSummary['manage_url'],
            ];
        }

        $onboardingTotal = count($onboardingSteps);
        $onboardingDone = collect($onboardingSteps)->where('done', true)->count();
        $onboardingComplete = $onboardingTotal > 0 && $onboardingDone === $onboardingTotal;

        // K8s cluster gone / unreachable. PollDoksClusterStatusJob (and the EKS
        // counterpart) flip the server to STATUS_ERROR and stash a human
        // message in meta.kubernetes.last_error when the provider returns 404
        // ("cluster deleted in console") or hits its retry cap. We render
        // those as a prominent error banner instead of letting the overview
        // look like a working cluster with empty tiles.
        $isK8sHost = ($this->server->meta['host_kind'] ?? null) === Server::HOST_KIND_KUBERNETES;
        $kubernetesError = null;
        if ($isK8sHost && $this->server->status === Server::STATUS_ERROR) {
            $k8sMeta = is_array($this->server->meta['kubernetes'] ?? null) ? $this->server->meta['kubernetes'] : [];
            $provider = (string) ($k8sMeta['provider'] ?? 'digitalocean');
            $kubernetesError = [
                'message' => (string) ($k8sMeta['last_error'] ?? __('Cluster status check failed.')),
                'cluster_name' => (string) ($k8sMeta['cluster_name'] ?? ''),
                'cluster_id' => (string) ($k8sMeta['cluster_id'] ?? ''),
                'provider' => $provider,
                'provider_label' => $provider === 'aws' ? 'AWS' : 'DigitalOcean',
                'provider_console_url' => $provider === 'aws'
                    ? 'https://console.aws.amazon.com/eks/home'
                    : 'https://cloud.digitalocean.com/kubernetes/clusters',
                'errored_at' => (string) ($k8sMeta['errored_at'] ?? ''),
            ];
        }

        return view('livewire.servers.workspace-overview', [
            'siteCount' => $sites->count(),
            'deployingCount' => $deployingCount,
            'latestDeployment' => $latestDeployment,
            'databaseSummary' => $databaseSummary,
            'healthSummary' => $healthSummary,
            'healthCockpitSummary' => Feature::active('workspace.health')
                ? $this->healthCockpitSummary(app(ServerHealthCockpit::class))
                : null,
            'patchAdvisorSummary' => Feature::active('workspace.patch_advisor')
                ? $this->patchAdvisorSummary(app(ServerPatchAdvisor::class))
                : null,
            'releaseHygieneSummary' => Feature::active('workspace.release_hygiene')
                ? $this->releaseHygieneSummary(app(ServerReleaseHygiene::class))
                : null,
            'costCardSummary' => Feature::active('workspace.server_cost')
                ? app(ServerCostCard::class)->overviewSummary($this->server)
                : null,
            'sharedHostSummary' => workspace_shared_host_active()
                ? app(SharedHostReport::class)->overviewSummary($this->server)
                : (workspace_shared_host_preview_active()
                    ? app(SharedHostReport::class)->overviewSummary($this->server, preview: true)
                    : null),
            'containerLaunch' => $this->containerLaunchSummary(),
            'hasProfileSshKeys' => $hasProfileSshKeys,
            'serverHasPersonalProfileKey' => $serverHasPersonalProfileKey,
            'installedStack' => InstalledStack::fromMeta($this->server),
            'installedStackDiverges' => InstalledStack::fromMeta($this->server)->divergesFromRequest($this->server),
            'openInsightsCount' => $openInsightsCount,
            'criticalInsightsCount' => $criticalInsightsCount,
            'notificationSummary' => $notificationSummary,
            'backgroundSummary' => $backgroundSummary,
            'kubernetesError' => $kubernetesError,
            'latestMetricSnapshot' => $latestMetricSnapshot,
            'sitesPreview' => $sitesPreview,
            'sitesPreviewLatestDeploys' => $sitesPreviewLatestDeploys,
            'onboardingSteps' => $onboardingSteps,
            'onboardingDone' => $onboardingDone,
            'onboardingTotal' => $onboardingTotal,
            'onboardingComplete' => $onboardingComplete,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'cacheTileData' => $cacheTileData,
            'cacheTileEngine' => $cacheTileEngine,
            'databaseTileData' => $databaseTileData,
            'isDedicatedServiceRoleHost' => $isDedicatedServiceRoleHost,
            'isDatabaseRoleHost' => $isDatabaseRoleHost,
        ]);
    }

    /**
     * @return array{overall: string, alert_count: int}|null
     */
    private function healthCockpitSummary(ServerHealthCockpit $cockpit): ?array
    {
        if (! $this->server->isVmHost()) {
            return null;
        }

        $report = $cockpit->forServer($this->server);

        return [
            'overall' => $report['overall'],
            'alert_count' => $report['alert_count'],
        ];
    }

    /**
     * @return array{overall: string, alert_count: int, security: int, reboot_required: ?bool}|null
     */
    private function patchAdvisorSummary(ServerPatchAdvisor $advisor): ?array
    {
        if (! $this->server->isVmHost()) {
            return null;
        }

        $report = $advisor->forServer($this->server);

        return [
            'overall' => $report['overall'],
            'alert_count' => $report['alert_count'],
            'security' => $report['packages']['security'],
            'reboot_required' => $report['reboot']['required'],
        ];
    }

    /**
     * @return array{overall: string, alert_count: int, sites_over_keep: int}|null
     */
    private function releaseHygieneSummary(ServerReleaseHygiene $hygiene): ?array
    {
        if (! $this->server->isVmHost()) {
            return null;
        }

        $report = $hygiene->forServer($this->server);

        return [
            'overall' => $report['overall'],
            'alert_count' => $report['alert_count'],
            'sites_over_keep' => $report['releases']['sites_over_keep'],
        ];
    }
}
