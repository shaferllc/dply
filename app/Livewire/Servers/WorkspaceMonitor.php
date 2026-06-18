<?php

namespace App\Livewire\Servers;

use App\Jobs\DeployGuestMetricsCallbackEnvJob;
use App\Jobs\RunServerMonitoringProbeJob;
use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\UpgradeGuestMetricsScriptJob;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Servers\Concerns\ConfirmsServerMonitoringInstall;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesMonitorDiagnostics;
use App\Livewire\Servers\Concerns\ManagesMonitorNotifications;
use App\Livewire\Servers\Concerns\ManagesMonitorProbe;
use App\Livewire\Servers\Concerns\ManagesMonitorThresholds;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerPackageInstalls;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\Team;
use App\Models\User;
use App\Modules\Insights\Services\InsightCorrelationService;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestPushService;
use App\Services\Servers\ServerMetricsGuestPushVerifier;
use App\Services\Servers\ServerMetricsGuestScript;
use App\Services\Servers\ServerMetricsRangeQuery;
use App\Support\Servers\MonitorWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceMonitor extends Component
{
    use ConfirmsServerMonitoringInstall;
    use ManagesMonitorDiagnostics;
    use ManagesMonitorNotifications;
    use ManagesMonitorProbe;
    use ManagesMonitorThresholds;
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use RendersWorkspacePlaceholder;
    use RunsServerPackageInstalls;

    public ?string $metrics_error = null;

    /** Tracks poll transitions so we flash once when a background probe finishes. */
    public bool $wasProbePending = false;

    /** Metrics page time range: '1h' | '6h' | '24h' | '7d' | '30d'. Persisted client-side via localStorage on the segmented control. */
    public string $metricsRange = '1h';

    /** Workspace tab: 'status' (health + current usage) | 'history' (charts) | 'notifications' (alert routing) | 'diagnostics' (repair / inspect tooling). */
    public string $monitor_workspace_tab = 'status';

    /** Notification subscription form properties */
    public string $notifAddChannelId = '';

    /** @var list<string> */
    public array $notifAddEventKeys = [];

    /** Threshold settings (server overrides) - null means use config default */
    public ?float $thresholdCpu = null;

    public ?float $thresholdMem = null;

    public ?float $thresholdLoad = null;

    /** Threshold input values for the edit form */
    public float $thresholdCpuInput = 85.0;

    public float $thresholdMemInput = 85.0;

    public float $thresholdLoadInput = 4.0;

    /** Whether threshold inputs are in edit mode */
    public bool $editingThresholds = false;

    /**
     * Identifies which Diagnostics action populated the shared $remote_output / $remote_error
     * slots so the banner can render per-kind copy. Null when no banner should display.
     * One of: 'repair' | 'diagnostics' | 'inspect'.
     */
    public ?string $remote_output_kind = null;

    public function setMonitorWorkspaceTab(string $tab): void
    {
        if (! in_array($tab, ['status', 'history', 'notifications', 'diagnostics'], true)) {
            return;
        }
        $this->monitor_workspace_tab = $tab;
    }


    /* ========================================================================
     * Notification Subscription Management
     * ======================================================================== */

    /**
     * Override from CreatesNotificationChannelInline to scope channels to org.
     */
    protected function creatableChannelOwner(): User|Organization|Team
    {
        $user = Auth::user();
        if ($user === null) {
            throw new \RuntimeException('No authenticated user for channel creation.');
        }

        $org = $user->currentOrganization();
        if ($org !== null) {
            return $org;
        }

        return $user;
    }


    /* ========================================================================
     * Threshold Configuration
     * ======================================================================== */


    public function setMetricsRange(string $range): void
    {
        if (! ServerMetricsRangeQuery::isValidRange($range)) {
            return;
        }
        $this->metricsRange = $range;
    }


    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->server->refresh();
        $this->wasProbePending = $this->probePendingFromMeta($this->server->meta ?? []);
        $this->syncThresholdSettingsFromServer();
    }


    public function render(): View
    {
        $this->server->refresh();

        $allowedTabs = ['status', 'history', 'notifications', 'diagnostics'];
        if (! in_array($this->monitor_workspace_tab, $allowedTabs, true)) {
            $this->monitor_workspace_tab = 'status';
        }

        $needsMetrics = in_array($this->monitor_workspace_tab, ['status', 'history'], true);
        $needsNotifications = $this->monitor_workspace_tab === 'notifications';

        $latest = ServerMetricSnapshot::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('captured_at')
            ->first();

        $chartLimit = (int) config('server_metrics.chart.max_points', 96);
        $tz = config('app.timezone');
        $chartFrom = null;
        $chartTo = null;
        $rangeMetricSeries = [];
        $rangeSampleCount = 0;
        $rangeBucketSeconds = 0;
        $metricStatuses = [
            'cpu' => 'unknown',
            'mem' => 'unknown',
            'disk' => 'unknown',
            'load' => 'unknown',
            'network' => 'healthy',
        ];
        $thresholdCpu = (float) config('insights.thresholds.cpu_warn_pct', 85);
        $thresholdMem = (float) config('insights.thresholds.mem_warn_pct', 85);
        $thresholdLoad = (float) config('insights.thresholds.load_warn', 4.0);
        $thresholdDiskWarn = 85.0;

        if ($needsMetrics) {
            // Bucketed series per metric across the operator-chosen range.
            // ServerMetricsRangeQuery handles min/avg/max bucketing in PHP so the
            // 30d view stays around ~180 points instead of ~43k raw rows.
            $rangeData = app(ServerMetricsRangeQuery::class)->fetch($this->server, $this->metricsRange);
            $chartFrom = $rangeData['from'];
            $chartTo = $rangeData['to'];
            $rangeMetricSeries = $rangeData['metrics'];
            $rangeSampleCount = $rangeData['sample_count'];
            $rangeBucketSeconds = $rangeData['bucket_seconds'];

            // Threshold tints for per-panel header icon + KPI. Use server-specific
            // thresholds if set, otherwise fall back to config defaults.
            $effectiveThresholds = $this->effectiveThresholds();
            $thresholdCpu = $effectiveThresholds['cpu'];
            $thresholdMem = $effectiveThresholds['mem'];
            $thresholdLoad = $effectiveThresholds['load'];

            $statusFor = function (?float $value, float $warn, float $critical): string {
                if ($value === null) {
                    return 'unknown';
                }
                if ($value >= $critical) {
                    return 'critical';
                }
                if ($value >= $warn) {
                    return 'warning';
                }

                return 'healthy';
            };

            $latestPayload = is_array($rangeData['latest_payload']) ? $rangeData['latest_payload'] : [];
            $metricStatuses = [
                'cpu' => $statusFor($this->floatOrNull($latestPayload['cpu_pct'] ?? null), $thresholdCpu, 95.0),
                'mem' => $statusFor($this->floatOrNull($latestPayload['mem_pct'] ?? null), $thresholdMem, 95.0),
                'disk' => $statusFor($this->floatOrNull($latestPayload['disk_pct'] ?? null), $thresholdDiskWarn, 95.0),
                'load' => $statusFor($this->floatOrNull($latestPayload['load_1m'] ?? null), $thresholdLoad, $thresholdLoad * 1.5),
                'network' => 'healthy',
            ];
        }

        $meta = $this->server->meta ?? [];
        $sshReachable = (bool) ($meta['monitoring_ssh_reachable'] ?? false);
        $pythonInstalled = (bool) ($meta['monitoring_python_installed'] ?? false);
        $probePending = ! empty($meta['monitoring_probe_pending']);
        $storedSnapshotCount = ServerMetricSnapshot::query()
            ->where('server_id', $this->server->id)
            ->count();

        $guestPush = app(ServerMetricsGuestPushService::class);
        $guestPushCronExpression = $guestPush->isEnabled()
            ? $guestPush->normalizedGuestPushCronExpression()
            : null;
        $guestPushVerification = $guestPush->isEnabled()
            ? app(ServerMetricsGuestPushVerifier::class)->summary($this->server)
            : null;

        if (
            $guestPushVerification !== null
            && ! $guestPushVerification['script_current']
            && $sshReachable
            && $pythonInstalled
        ) {
            UpgradeGuestMetricsScriptJob::dispatch(
                $this->server->id,
                app(ServerMetricsGuestScript::class)->bundledSha256()
            );
        }

        // Self-heal stale callback env files. Earlier deploys could
        // bake the literal "${DPLY_PUBLIC_APP_URL}/api/metrics" string
        // into ~/.dply/metrics-callback.env when the .env file
        // referenced an undefined-yet-during-load variable, leaving the
        // guest cron emitting "ValueError: unknown url type" forever.
        // If the URL stored when we last deployed differs from what
        // guestPushUrl() resolves to today (or contains an unresolved
        // "${...}" placeholder), redeploy the env file. Idempotent —
        // the job is ShouldBeUnique on serverId.
        if (
            $guestPush->isEnabled()
            && $sshReachable
            && $pythonInstalled
            && ! empty($this->server->ip_address)
        ) {
            $expectedPushUrl = $guestPush->guestPushUrl();
            $deployedPushUrl = (string) ($meta['monitoring_guest_push_callback_url'] ?? '');
            // Only redeploy when we have a record of a previous deploy
            // AND it disagrees with what guestPushUrl() resolves to now
            // (or carries an unresolved "${...}" placeholder). The
            // never-deployed case belongs to Install / Repair flows —
            // dispatching a heal job there would SSH on every render,
            // which also disturbs feature tests.
            $needsRedeploy = $deployedPushUrl !== ''
                && $expectedPushUrl !== ''
                && (str_contains($deployedPushUrl, '${') || $deployedPushUrl !== $expectedPushUrl);
            if ($needsRedeploy) {
                DeployGuestMetricsCallbackEnvJob::dispatch($this->server->id);
            }
        }

        // Pick up the latest monitoring-install action so the install
        // card can show "Installing… started Xm ago" even after the
        // operator reloads. The cache-only $servicesRemoteTaskId only
        // survives within the current Livewire instance.
        $monitoringInstallAction = ServerManageAction::query()
            ->where('server_id', $this->server->id)
            ->where('task_name', 'services-install:install_monitoring_prerequisites')
            ->latest('id')
            ->first();
        $monitoringInstallInProgress = $monitoringInstallAction !== null
            && in_array($monitoringInstallAction->status, [
                ServerManageAction::STATUS_QUEUED,
                ServerManageAction::STATUS_RUNNING,
            ], true);

        $latestPayloadSummary = $latest !== null
            ? $this->summarizeLatestPayload($latest->payload ?? [])
            : [];
        $deploymentContext = $this->deploymentContext($this->server);
        $sampleAgeMinutesRaw = $latest?->captured_at?->diffInMinutes(now(), false);
        $sampleAgeMinutes = $sampleAgeMinutesRaw !== null ? abs((int) ceil($sampleAgeMinutesRaw)) : null;
        $sampleTimestampInFuture = $sampleAgeMinutesRaw !== null && $sampleAgeMinutesRaw < 0;
        $workspace = $this->server->workspace;
        $routingSummary = [
            'server_routes' => NotificationSubscription::query()
                ->where('subscribable_type', Server::class)
                ->where('subscribable_id', $this->server->id)
                ->count(),
            'project_routes' => $workspace
                ? NotificationSubscription::query()
                    ->where('subscribable_type', get_class($workspace))
                    ->where('subscribable_id', $workspace->id)
                    ->count()
                : 0,
            'has_project' => $workspace !== null,
        ];

        // Notification subscriptions data (Notifications tab only)
        $serverNotifSubscriptions = $needsNotifications
            ? NotificationSubscription::query()
                ->where('subscribable_type', Server::class)
                ->where('subscribable_id', $this->server->id)
                ->with('channel')
                ->get()
            : collect();

        $assignableChannels = $needsNotifications
            ? AssignableNotificationChannels::forUser(
                Auth::user(),
                Auth::user()?->currentOrganization()
            )->sortBy('label')->values()
            : collect();

        $serverEventLabels = $needsNotifications
            ? config('notification_events.categories.server.events', [])
            : [];

        $pollRemoteTaskSeconds = (int) config('server_metrics.ui.poll_remote_task_seconds', 2);

        $viewData = MonitorWorkspaceViewData::for(
            $this->server,
            $this,
            $latest,
            $guestPushVerification,
            $sampleAgeMinutes,
            $sampleTimestampInFuture,
            $guestPushVerification['last_guest_sample_at'] ?? null,
            $pollRemoteTaskSeconds,
        );

        return view('livewire.servers.workspace-monitor', [
            ...$viewData,
            'latest' => $latest,
            'chartPointLimit' => $chartLimit,
            'chartFrom' => $chartFrom,
            'chartTo' => $chartTo,
            'chartTimezone' => $tz,
            'rangeMetricSeries' => $rangeMetricSeries,
            'rangeBucketSeconds' => $rangeBucketSeconds,
            'rangeSampleCount' => $rangeSampleCount,
            'metricStatuses' => $metricStatuses,
            'thresholds' => [
                'cpu' => $thresholdCpu,
                'mem' => $thresholdMem,
                'disk' => $thresholdDiskWarn,
                'load' => $thresholdLoad,
            ],
            'metricsRangeOptions' => array_keys(ServerMetricsRangeQuery::RANGES),
            'storedSnapshotCount' => $storedSnapshotCount,
            'showMetricsPanels' => $pythonInstalled || $storedSnapshotCount > 0,
            'opsReady' => $this->serverOpsReady(),
            'isDeployer' => $this->currentUserIsDeployer(),
            'monitorLastGuestSampleAt' => $guestPushVerification['last_guest_sample_at'] ?? null,
            'probePending' => $probePending,
            'pollProbeSeconds' => (int) config('server_metrics.ui.poll_probe_seconds', 3),
            'pollRemoteTaskSeconds' => $pollRemoteTaskSeconds,
            'pollAutoRefreshSeconds' => (int) config('server_metrics.ui.auto_refresh_seconds', 60),
            'guestPushCronExpression' => $guestPushCronExpression,
            'guestPushVerification' => $guestPushVerification,
            'latestPayloadSummary' => $latestPayloadSummary,
            'deploymentContext' => $deploymentContext,
            'sampleAgeMinutes' => $sampleAgeMinutes,
            'sampleTimestampInFuture' => $sampleTimestampInFuture,
            'routingSummary' => $routingSummary,
            'monitoringInstallAction' => $monitoringInstallAction,
            'monitoringInstallInProgress' => $monitoringInstallInProgress,
            'serverNotifSubscriptions' => $serverNotifSubscriptions,
            'assignableChannels' => $assignableChannels,
            'serverEventLabels' => $serverEventLabels,
            'editingThresholds' => $this->editingThresholds,
            'thresholdCpuInput' => $this->thresholdCpu ?? $thresholdCpu,
            'thresholdMemInput' => $this->thresholdMem ?? $thresholdMem,
            'thresholdLoadInput' => $this->thresholdLoad ?? $thresholdLoad,
        ]);
    }
}
