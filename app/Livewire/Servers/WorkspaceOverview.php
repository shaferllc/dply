<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\Concerns\BuildsContainerLaunchSummary;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\InstalledStack;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

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
class WorkspaceOverview extends Component
{
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
            \App\Jobs\PollEksClusterStatusJob::dispatch($this->server);
        } else {
            \App\Jobs\PollDoksClusterStatusJob::dispatch($this->server);
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
        $this->server->refresh();

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
            'active_workers' => \App\Models\SupervisorProgram::query()
                ->where('server_id', $this->server->id)
                ->whereIn('program_type', \App\Livewire\Servers\WorkspaceQueueWorkers::QUEUE_TYPES)
                ->where('is_active', true)
                ->count(),
            'active_schedules' => \App\Models\ServerBackupSchedule::query()
                ->where('server_id', $this->server->id)
                ->where('is_active', true)
                ->count(),
            'paused_schedules' => \App\Models\ServerBackupSchedule::query()
                ->where('server_id', $this->server->id)
                ->where('is_active', false)
                ->count(),
            'failed_backups_7d' => (int) \App\Models\ServerDatabaseBackup::query()
                ->whereIn('server_database_id', $this->server->serverDatabases()->pluck('id'))
                ->where('status', 'failed')
                ->where('created_at', '>=', $weekAgo)
                ->count()
                + (int) \App\Models\SiteFileBackup::query()
                    ->whereIn('site_id', $this->server->sites()->pluck('id'))
                    ->where('status', 'failed')
                    ->where('created_at', '>=', $weekAgo)
                    ->count(),
        ];

        // Most-recent metric snapshot for the live CPU/Mem/Disk/Load card.
        // One row, cheap. Same source the Monitor page uses — duplicating it
        // here means the overview reflects current load without a full
        // Monitor-tab fetch and the operator can decide whether to drill in.
        $latestMetricSnapshot = ServerMetricSnapshot::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('captured_at')
            ->first();

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
        $monitorInstalled = $latestMetricSnapshot !== null
            && is_array($latestMetricSnapshot->payload ?? null)
            && isset($latestMetricSnapshot->payload['cpu_pct']);
        $hasBackupSchedule = ($backgroundSummary['active_schedules'] ?? 0)
            + ($backgroundSummary['paused_schedules'] ?? 0) > 0;

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
        if (! $isContainerHostForChecklist) {
            $onboardingSteps[] = [
                'key' => 'monitor',
                'label' => __('Install the monitor agent'),
                'help' => __('Streams CPU / memory / disk for the Live load card and Insights.'),
                'done' => $monitorInstalled,
                'cta_label' => __('Install'),
                'cta_route' => route('servers.monitor', $this->server),
            ];
            $onboardingSteps[] = [
                'key' => 'backups',
                'label' => __('Schedule backups'),
                'help' => __('Automatic database + site-files backups on a cron you choose.'),
                'done' => $hasBackupSchedule,
                'cta_label' => __('Open Backups'),
                'cta_route' => route('servers.backups', $this->server),
            ];
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
        ]);
    }

}
