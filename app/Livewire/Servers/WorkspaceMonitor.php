<?php

namespace App\Livewire\Servers;

use App\Jobs\DeployGuestMetricsCallbackEnvJob;
use App\Jobs\RunServerMonitoringProbeJob;
use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\UpgradeGuestMetricsScriptJob;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Servers\Concerns\ConfirmsServerMonitoringInstall;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerPackageInstalls;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Insights\InsightCorrelationService;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestPushService;
use App\Services\Servers\ServerMetricsGuestPushVerifier;
use App\Services\Servers\ServerMetricsGuestScript;
use App\Services\Servers\ServerMetricsRangeQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceMonitor extends Component
{
    use ConfirmsServerMonitoringInstall;
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
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

    /**
     * Banner status derived from the queued cache payload (queued/running/finished/failed)
     * or the inline-path success/error state. Empty string means "no banner".
     */
    public function getDiagnosticsBannerStatusProperty(): string
    {
        if ($this->servicesRemoteTaskId !== null && $this->servicesRemoteTaskId !== '') {
            $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->servicesRemoteTaskId));
            if (is_array($payload)) {
                return match ((string) ($payload['status'] ?? '')) {
                    'queued' => 'queued',
                    'running' => 'running',
                    'finished' => 'completed',
                    'failed' => 'failed',
                    default => 'running',
                };
            }

            return 'running';
        }
        if (is_string($this->remote_error) && $this->remote_error !== '') {
            return 'failed';
        }
        if (is_string($this->remote_output) && $this->remote_output !== '') {
            return 'completed';
        }

        return '';
    }

    /**
     * Splits the shared $remote_output string into the banner's expected list<string> shape.
     * Empty array when no transcript is available yet.
     *
     * @return list<string>
     */
    public function getDiagnosticsBannerOutputLinesProperty(): array
    {
        if (! is_string($this->remote_output) || $this->remote_output === '') {
            return [];
        }

        return explode("\n", $this->remote_output);
    }

    /**
     * Clears the diagnostics banner and any queued cache entry so it can dismiss cleanly.
     * Safe to call when no banner is showing — no-op.
     */
    public function dismissDiagnosticsBanner(): void
    {
        $this->authorize('view', $this->server);

        if ($this->servicesRemoteTaskId !== null && $this->servicesRemoteTaskId !== '') {
            Cache::forget(ServerManageRemoteSshJob::cacheKey($this->servicesRemoteTaskId));
            $this->servicesRemoteTaskId = null;
        }
        $this->remote_output = null;
        $this->remote_error = null;
        $this->remote_output_kind = null;
    }

    /* ========================================================================
     * Notification Subscription Management
     * ======================================================================== */

    /**
     * Override from CreatesNotificationChannelInline to scope channels to org.
     */
    protected function creatableChannelOwner(): \App\Models\User|\App\Models\Organization|\App\Models\Team
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

    /**
     * After creating a channel inline, auto-select it in the subscription form.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->notifAddChannelId = $channelId;
    }

    /**
     * Add notification subscription(s) for this server.
     */
    public function addServerNotificationSubscription(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change notification subscriptions.'));

            return;
        }

        $this->validate([
            'notifAddChannelId' => ['required', 'string', 'exists:notification_channels,id'],
            'notifAddEventKeys' => ['required', 'array', 'min:1'],
            'notifAddEventKeys.*' => ['string', 'in:server.automatic_updates,server.ssh_login,server.insights_alerts,server.monitoring'],
        ], [], [
            'notifAddChannelId' => __('channel'),
            'notifAddEventKeys' => __('notification types'),
        ]);

        $org = Auth::user()?->currentOrganization();
        $allowed = AssignableNotificationChannels::forUser(Auth::user(), $org)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (! in_array($this->notifAddChannelId, $allowed, true)) {
            $this->addError('notifAddChannelId', __('Channel is not assignable to this server.'));

            return;
        }

        $channel = NotificationChannel::query()->findOrFail($this->notifAddChannelId);
        Gate::authorize('manageNotificationChannels', $channel->owner);

        $created = 0;
        foreach ($this->notifAddEventKeys as $eventKey) {
            $row = NotificationSubscription::firstOrCreate([
                'notification_channel_id' => $channel->id,
                'subscribable_type' => Server::class,
                'subscribable_id' => $this->server->id,
                'event_key' => $eventKey,
            ]);
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->notifAddChannelId = '';
        $this->notifAddEventKeys = [];
        $this->toastSuccess(__('Added :count subscription(s) routing this server\'s events to :channel.', [
            'count' => $created,
            'channel' => $channel->label,
        ]));
    }

    /**
     * Remove a notification subscription from this server.
     */
    public function removeServerNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change notification subscriptions.'));

            return;
        }

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereKey($subscriptionId)
            ->first();

        if ($sub === null) {
            return;
        }

        // Only allow removal when the user can manage the underlying channel
        $channel = $sub->channel;
        if ($channel instanceof NotificationChannel) {
            Gate::authorize('manageNotificationChannels', $channel->owner);
        }

        $sub->delete();
        $this->toastSuccess(__('Subscription removed.'));
    }

    /* ========================================================================
     * Threshold Configuration
     * ======================================================================== */

    /**
     * Load threshold settings from server meta or fallback to config defaults.
     */
    protected function syncThresholdSettingsFromServer(): void
    {
        $meta = $this->server->meta ?? [];
        $thresholds = $meta['metric_thresholds'] ?? [];

        $this->thresholdCpu = isset($thresholds['cpu_warn_pct'])
            ? (float) $thresholds['cpu_warn_pct']
            : null;
        $this->thresholdMem = isset($thresholds['mem_warn_pct'])
            ? (float) $thresholds['mem_warn_pct']
            : null;
        $this->thresholdLoad = isset($thresholds['load_warn'])
            ? (float) $thresholds['load_warn']
            : null;
    }

    /**
     * Get effective thresholds (server override or config default).
     *
     * @return array{cpu: float, mem: float, load: float}
     */
    protected function effectiveThresholds(): array
    {
        return [
            'cpu' => $this->thresholdCpu ?? (float) config('insights.thresholds.cpu_warn_pct', 85),
            'mem' => $this->thresholdMem ?? (float) config('insights.thresholds.mem_warn_pct', 85),
            'load' => $this->thresholdLoad ?? (float) config('insights.thresholds.load_warn', 4.0),
        ];
    }

    /**
     * Enable threshold editing mode.
     */
    public function startEditingThresholds(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        // Initialize input values to current effective thresholds
        $effective = $this->effectiveThresholds();
        $this->thresholdCpuInput = $effective['cpu'];
        $this->thresholdMemInput = $effective['mem'];
        $this->thresholdLoadInput = $effective['load'];

        $this->editingThresholds = true;
    }

    /**
     * Cancel threshold editing without saving.
     */
    public function cancelEditingThresholds(): void
    {
        $this->editingThresholds = false;
        $this->resetErrorBag();
    }

    /**
     * Save threshold settings to server meta.
     */
    public function saveThresholdSettings(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'thresholdCpuInput' => ['required', 'numeric', 'min:1', 'max:99'],
            'thresholdMemInput' => ['required', 'numeric', 'min:1', 'max:99'],
            'thresholdLoadInput' => ['required', 'numeric', 'min:0.1', 'max:100'],
        ], [], [
            'thresholdCpuInput' => __('CPU threshold'),
            'thresholdMemInput' => __('Memory threshold'),
            'thresholdLoadInput' => __('Load threshold'),
        ]);

        $meta = $this->server->meta ?? [];
        $meta['metric_thresholds'] = [
            'cpu_warn_pct' => round($this->thresholdCpuInput, 1),
            'mem_warn_pct' => round($this->thresholdMemInput, 1),
            'load_warn' => round($this->thresholdLoadInput, 2),
        ];

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncThresholdSettingsFromServer();
        $this->editingThresholds = false;
        $this->toastSuccess(__('Metric thresholds saved. KPI warning colors will update on the next sample.'));
    }

    /**
     * Clear server-specific thresholds and revert to config defaults.
     */
    public function resetThresholdsToDefaults(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $meta = $this->server->meta ?? [];
        unset($meta['metric_thresholds']);

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncThresholdSettingsFromServer();
        $this->editingThresholds = false;
        $this->toastSuccess(__('Reverted to organization defaults.'));
    }

    public function setMetricsRange(string $range): void
    {
        if (! ServerMetricsRangeQuery::isValidRange($range)) {
            return;
        }
        $this->metricsRange = $range;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null || ! is_numeric($value) ? null : (float) $value;
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->server->refresh();
        $this->wasProbePending = $this->probePendingFromMeta($this->server->meta ?? []);
        $this->syncThresholdSettingsFromServer();
    }

    #[On('monitoring-probe-requested')]
    public function onMonitoringProbeRequested(): void
    {
        $this->queueMonitoringProbe();
    }

    /**
     * Queues an SSH probe (python3 check) — does not block the request on SSH.
     */
    public function queueMonitoringProbe(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            return;
        }

        $server = $this->server->fresh();
        $meta = $server->meta ?? [];

        $this->releaseStaleProbePending($server, $meta);

        $server = $this->server->fresh();
        $meta = $server->meta ?? [];

        if (! empty($meta['monitoring_probe_pending'])) {
            return;
        }

        $meta['monitoring_probe_pending'] = true;
        $meta['monitoring_probe_pending_at'] = now()->toIso8601String();
        $server->update(['meta' => $meta]);
        $this->server = $server->fresh();
        $this->wasProbePending = true;

        $pending = RunServerMonitoringProbeJob::dispatch($this->server->id);
        $queue = config('server_metrics.probe.queue');
        if (is_string($queue) && $queue !== '') {
            $pending->onQueue($queue);
        }
    }

    public function syncMonitoringProbeStatus(): void
    {
        $this->authorize('view', $this->server);
        $this->server->refresh();
        $meta = $this->server->meta ?? [];
        $this->releaseStaleProbePending($this->server, $meta);

        $this->server->refresh();
        $meta = $this->server->meta ?? [];

        $pending = $this->probePendingFromMeta($meta);
        if ($this->wasProbePending && ! $pending) {
            $this->toastSuccess(__('Monitoring status updated.'));
        }
        $this->wasProbePending = $pending;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function probePendingFromMeta(array $meta): bool
    {
        return ! empty($meta['monitoring_probe_pending']);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function releaseStaleProbePending(Server $server, array &$meta): void
    {
        if (empty($meta['monitoring_probe_pending']) || empty($meta['monitoring_probe_pending_at'])) {
            return;
        }

        try {
            $at = Carbon::parse((string) $meta['monitoring_probe_pending_at']);
        } catch (\Throwable) {
            unset($meta['monitoring_probe_pending'], $meta['monitoring_probe_pending_at']);
            $server->update(['meta' => $meta]);
            $this->server = $server->fresh();

            return;
        }

        if ($at->lt(now()->subMinutes(15))) {
            unset($meta['monitoring_probe_pending'], $meta['monitoring_probe_pending_at']);
            $server->update(['meta' => $meta]);
            $this->server = $server->fresh();
        }
    }

    public function verifyGuestPush(): void
    {
        $this->authorize('view', $this->server);
        $this->metrics_error = null;

        if (! $this->serverOpsReady()) {
            $this->metrics_error = __('Provisioning and SSH must be ready before verifying guest push.');

            return;
        }

        $meta = $this->server->meta ?? [];
        if (! ($meta['monitoring_ssh_reachable'] ?? false)) {
            $this->metrics_error = __('Dply could not reach this server over SSH the last time we checked. Recheck status first, then verify guest push.');

            return;
        }

        try {
            $server = $this->server->fresh();
            $summary = app(ServerMetricsGuestPushVerifier::class)->refreshRemoteState($server);
            $queuedRepairs = [];

            if (
                ! $summary['configured']
                || ! $summary['cron_current']
                || ! $summary['callback_env_deployed']
                || ! $summary['cron_installed']
            ) {
                app(ServerMetricsGuestPushService::class)->ensureConfigured($server->fresh());
                $queuedRepairs[] = __('callback env and cron sync');
            }

            if (! $summary['script_current']) {
                UpgradeGuestMetricsScriptJob::dispatch(
                    $server->id,
                    app(ServerMetricsGuestScript::class)->bundledSha256()
                );
                $queuedRepairs[] = __('monitor script repair');
            }

            $this->toastSuccess($queuedRepairs === []
                ? __('Guest monitoring wiring verified.')
                : __('Monitor rechecked. Queued: :items.', ['items' => implode(', ', $queuedRepairs)]));

            $this->server->refresh();
        } catch (\Throwable $e) {
            $this->metrics_error = $e->getMessage();
        }
    }

    public function inspectMetricsCallbackEnv(): void
    {
        $this->authorize('view', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;
        $this->remote_output_kind = 'inspect';

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before inspecting the callback env.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        $server = $this->server->fresh();
        $sshUser = trim((string) $server->ssh_user) ?: 'root';
        $script = str_replace('__SSH_USER__', addslashes($sshUser), <<<'BASH'
TARGET_USER="__SSH_USER__"
CURRENT_ENV="$HOME/.dply/metrics-callback.env"
TARGET_HOME="$(getent passwd "$TARGET_USER" | cut -d: -f6 2>/dev/null || true)"
if [ -z "$TARGET_HOME" ] && [ "$TARGET_USER" = "root" ]; then
  TARGET_HOME="/root"
fi
TARGET_ENV=""
if [ -n "$TARGET_HOME" ]; then
  TARGET_ENV="$TARGET_HOME/.dply/metrics-callback.env"
fi

ENV_FILE=""
if [ -f "$CURRENT_ENV" ]; then
  ENV_FILE="$CURRENT_ENV"
elif [ -n "$TARGET_ENV" ] && [ -f "$TARGET_ENV" ]; then
  ENV_FILE="$TARGET_ENV"
fi

if [ -z "$ENV_FILE" ]; then
  if [ -n "$TARGET_ENV" ]; then
    echo "metrics-callback.env is missing at $CURRENT_ENV and $TARGET_ENV"
  else
    echo "metrics-callback.env is missing at $CURRENT_ENV"
  fi
  exit 0
fi

echo "Inspecting: $ENV_FILE"
awk '
  BEGIN { FS="="; OFS="=" }
  /^DPLY_METRICS_CALLBACK_TOKEN=/ {
    token = substr($0, length("DPLY_METRICS_CALLBACK_TOKEN=") + 1)
    if (length(token) > 12) {
      print "DPLY_METRICS_CALLBACK_TOKEN", substr(token, 1, 6) "..." substr(token, length(token) - 3)
    } else {
      print "DPLY_METRICS_CALLBACK_TOKEN", "***"
    }
    next
  }
  { print $0 }
' "$ENV_FILE"
BASH);

        try {
            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedServicesScript(
                    $server,
                    'metrics-callback-env:inspect',
                    $script,
                    60,
                    __('Callback env inspection finished.'),
                    __('TaskRunner (SSH)').' — '.__('Inspect callback env'),
                );

                return;
            }

            $out = $this->runManageInlineBash(
                $server,
                'metrics-callback-env:inspect',
                $script,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                60,
            );

            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->remote_error = null;
            $this->toastSuccess(__('Callback env inspection finished.'));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    public function runMonitorCallbackDiagnostics(): void
    {
        $this->authorize('view', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;
        $this->remote_output_kind = 'diagnostics';

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before running callback diagnostics.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        $server = $this->server->fresh();
        $sshUser = trim((string) $server->ssh_user) ?: 'root';
        $script = str_replace('__SSH_USER__', addslashes($sshUser), <<<'BASH'
TARGET_USER="__SSH_USER__"
TARGET_HOME="$(getent passwd "$TARGET_USER" | cut -d: -f6 2>/dev/null || true)"
if [ -z "$TARGET_HOME" ] && [ "$TARGET_USER" = "root" ]; then
  TARGET_HOME="/root"
fi
if [ -z "$TARGET_HOME" ]; then
  TARGET_HOME="$HOME"
fi

SCRIPT_PATH="$TARGET_HOME/.dply/bin/server-metrics-snapshot.py"
ENV_FILE="$TARGET_HOME/.dply/metrics-callback.env"

echo "== monitor paths =="
echo "target_user=$TARGET_USER"
echo "target_home=$TARGET_HOME"
echo "script_path=$SCRIPT_PATH"
echo "env_file=$ENV_FILE"
echo

echo "== files =="
if [ -f "$SCRIPT_PATH" ]; then
  ls -l "$SCRIPT_PATH"
else
  echo "missing: $SCRIPT_PATH"
fi

if [ -f "$ENV_FILE" ]; then
  echo "present: $ENV_FILE"
  awk '
    BEGIN { FS="="; OFS="=" }
    /^DPLY_METRICS_CALLBACK_TOKEN=/ {
      token = substr($0, length("DPLY_METRICS_CALLBACK_TOKEN=") + 1)
      if (length(token) > 12) {
        print "DPLY_METRICS_CALLBACK_TOKEN", substr(token, 1, 6) "..." substr(token, length(token) - 3)
      } else {
        print "DPLY_METRICS_CALLBACK_TOKEN", "***"
      }
      next
    }
    { print $0 }
  ' "$ENV_FILE"
else
  echo "missing: $ENV_FILE"
fi
echo

echo "== cron =="
if command -v sudo >/dev/null 2>&1; then
  sudo -n -u "$TARGET_USER" crontab -l 2>/dev/null | sed -n '/# BEGIN DPLY METRICS GUEST/,/# END DPLY METRICS GUEST/p'
else
  (crontab -l 2>/dev/null || true) | sed -n '/# BEGIN DPLY METRICS GUEST/,/# END DPLY METRICS GUEST/p'
fi
echo

echo "== local script run =="
if [ -x "$SCRIPT_PATH" ]; then
  timeout 20 env -i HOME="$TARGET_HOME" PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" python3 "$SCRIPT_PATH" 2>&1 | sed -n '1,20p'
else
  echo "script not executable or missing"
fi
echo

echo "== callback probe =="
if [ -f "$ENV_FILE" ]; then
  set -a
  . "$ENV_FILE"
  set +a
  if command -v curl >/dev/null 2>&1; then
    curl -I -sS --max-time 15 "$DPLY_METRICS_CALLBACK_URL" 2>&1 | sed -n '1,20p'
  else
    echo "curl not installed"
  fi
else
  echo "callback env missing"
fi
BASH);

        try {
            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedServicesScript(
                    $server,
                    'monitor-callback:diagnostics',
                    $script,
                    120,
                    __('Monitor callback diagnostics finished.'),
                    __('TaskRunner (SSH)').' — '.__('Run callback diagnostics'),
                );

                return;
            }

            $out = $this->runManageInlineBash(
                $server,
                'monitor-callback:diagnostics',
                $script,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                120,
            );

            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->remote_error = null;
            $this->toastSuccess(__('Monitor callback diagnostics finished.'));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    public function repairMonitorNow(): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;
        $this->remote_output_kind = 'repair';

        if ($this->currentUserIsDeployer()) {
            $msg = __('Deployers cannot repair monitor installs on servers.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before repairing the monitor.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        $server = $this->server->fresh();
        $guestScript = app(ServerMetricsGuestScript::class);
        $guestPush = app(ServerMetricsGuestPushService::class);

        if ($guestPush->plainTokenForDeploy($server) === null) {
            $guestPush->generateAndStoreToken($server);
            $server = $server->fresh();
        }

        $script = trim($guestScript->guestScriptDeployOnlyScript())
            ."\n\n".trim($guestPush->writeCallbackEnvFileBash($server))
            ."\n\n".trim($guestPush->installGuestMetricsCronBash());

        try {
            $out = $this->runManageInlineBash(
                $server,
                'monitor-repair-now',
                $script,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                120,
            );

            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->remote_error = null;

            $meta = $server->fresh()->meta ?? [];
            $meta['monitoring_callback_env_deployed'] = true;
            $meta['monitoring_callback_env_deployed_at'] = now()->toIso8601String();
            $meta['monitoring_guest_push_callback_url'] = $guestPush->guestPushUrl();
            $meta['monitoring_guest_cron_installed_at'] = now()->toIso8601String();
            $meta['monitoring_guest_push_cron_expression'] = $guestPush->normalizedGuestPushCronExpression();
            $server->forceFill(['meta' => $meta])->saveQuietly();

            app(ServerMetricsGuestPushVerifier::class)->refreshRemoteState($server->fresh());
            $this->server->refresh();
            $this->toastSuccess(__('Monitor repaired directly over SSH.'));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function summarizeLatestPayload(array $payload): array
    {
        $swapTotalKb = isset($payload['swap_total_kb']) ? (int) $payload['swap_total_kb'] : null;
        $swapUsedKb = isset($payload['swap_used_kb']) ? (int) $payload['swap_used_kb'] : null;

        return [
            'memory_available_bytes' => isset($payload['mem_available_kb']) ? (int) $payload['mem_available_kb'] * 1024 : null,
            'memory_total_bytes' => isset($payload['mem_total_kb']) ? (int) $payload['mem_total_kb'] * 1024 : null,
            'swap_total_bytes' => $swapTotalKb !== null ? $swapTotalKb * 1024 : null,
            'swap_used_bytes' => $swapUsedKb !== null ? $swapUsedKb * 1024 : null,
            'swap_pct' => $swapTotalKb && $swapUsedKb !== null
                ? round(($swapUsedKb / $swapTotalKb) * 100, 1)
                : null,
            'disk_free_bytes' => isset($payload['disk_free_bytes']) ? (int) $payload['disk_free_bytes'] : null,
            'inode_pct_root' => isset($payload['inode_pct_root']) ? (float) $payload['inode_pct_root'] : null,
            'cpu_count' => isset($payload['cpu_count']) ? (int) $payload['cpu_count'] : null,
            'load_per_cpu_1m' => isset($payload['load_per_cpu_1m']) ? (float) $payload['load_per_cpu_1m'] : null,
            'uptime_seconds' => isset($payload['uptime_seconds']) ? (int) $payload['uptime_seconds'] : null,
            'rx_bytes_per_sec' => isset($payload['rx_bytes_per_sec']) ? (float) $payload['rx_bytes_per_sec'] : null,
            'tx_bytes_per_sec' => isset($payload['tx_bytes_per_sec']) ? (float) $payload['tx_bytes_per_sec'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deploymentContext(Server $server): array
    {
        /** @var EloquentCollection<int, Site> $sites */
        $sites = $server->sites()
            ->with(['deployments' => fn ($query) => $query->limit(1)])
            ->orderBy('name')
            ->get();

        $latestDeployment = SiteDeployment::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->first();

        $latestFailedDeployment = SiteDeployment::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->where('status', SiteDeployment::STATUS_FAILED)
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->first();

        $activeSiteCount = $sites->filter(fn ($site) => $site->status !== Site::STATUS_ERROR)->count();
        $siteSummaries = $sites->take(3)->map(function ($site): array {
            $deployment = $site->deployments->first();

            return [
                'name' => $site->name,
                'status' => $site->status,
                'last_deploy_at' => $deployment?->finished_at?->toIso8601String(),
                'last_deploy_status' => $deployment?->status,
            ];
        })->values();

        return [
            'site_count' => $sites->count(),
            'active_site_count' => $activeSiteCount,
            'latest_deployment' => $latestDeployment,
            'latest_failed_deployment' => $latestFailedDeployment,
            'latest_correlation' => app(InsightCorrelationService::class)->correlateForNewFinding($server),
            'site_summaries' => $siteSummaries,
        ];
    }

    public function render(): View
    {
        $this->server->refresh();

        $latest = ServerMetricSnapshot::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('captured_at')
            ->first();

        $chartLimit = (int) config('server_metrics.chart.max_points', 96);

        // Bucketed series per metric across the operator-chosen range.
        // ServerMetricsRangeQuery handles min/avg/max bucketing in PHP so the
        // 30d view stays around ~180 points instead of ~43k raw rows.
        $rangeData = app(ServerMetricsRangeQuery::class)->fetch($this->server, $this->metricsRange);
        $chartFrom = $rangeData['from'];
        $chartTo = $rangeData['to'];
        $rangeMetricSeries = $rangeData['metrics'];
        $tz = config('app.timezone');

        // Threshold tints for per-panel header icon + KPI. Use server-specific
        // thresholds if set, otherwise fall back to config defaults.
        $effectiveThresholds = $this->effectiveThresholds();
        $thresholdCpu = $effectiveThresholds['cpu'];
        $thresholdMem = $effectiveThresholds['mem'];
        $thresholdLoad = $effectiveThresholds['load'];
        $thresholdDiskWarn = 85.0; // Disk has no insights threshold yet — match cpu/mem default.

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

        // Notification subscriptions data (for the Notifications tab)
        $serverNotifSubscriptions = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->with('channel')
            ->get();

        $assignableChannels = AssignableNotificationChannels::forUser(
            Auth::user(),
            Auth::user()?->currentOrganization()
        )->sortBy('label')->values();

        // Server-scoped notification event labels
        $serverEventLabels = config('notification_events.categories.server.events', []);

        return view('livewire.servers.workspace-monitor', [
            'latest' => $latest,
            'chartPointLimit' => $chartLimit,
            'chartFrom' => $chartFrom,
            'chartTo' => $chartTo,
            'chartTimezone' => $tz,
            'rangeMetricSeries' => $rangeMetricSeries,
            'rangeBucketSeconds' => $rangeData['bucket_seconds'],
            'rangeSampleCount' => $rangeData['sample_count'],
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
            'pollRemoteTaskSeconds' => (int) config('server_metrics.ui.poll_remote_task_seconds', 2),
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
            // Notification subscription data
            'serverNotifSubscriptions' => $serverNotifSubscriptions,
            'assignableChannels' => $assignableChannels,
            'serverEventLabels' => $serverEventLabels,
            // Threshold editing state
            'editingThresholds' => $this->editingThresholds,
            'thresholdCpuInput' => $this->thresholdCpu ?? $thresholdCpu,
            'thresholdMemInput' => $this->thresholdMem ?? $thresholdMem,
            'thresholdLoadInput' => $this->thresholdLoad ?? $thresholdLoad,
        ]);
    }
}
