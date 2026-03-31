<?php

namespace App\Livewire\Servers;

use App\Jobs\RunServerMonitoringProbeJob;
use App\Livewire\Servers\Concerns\ConfirmsServerMonitoringInstall;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerPackageInstalls;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Services\Servers\ServerMetricsCollector;
use App\Services\Servers\ServerMetricsGuestPushService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceMonitor extends Component
{
    use ConfirmsServerMonitoringInstall;
    use InteractsWithServerWorkspace;
    use RunsServerPackageInstalls;

    public bool $autoRefresh = false;

    public ?string $metrics_error = null;

    /** Tracks poll transitions so we flash once when a background probe finishes. */
    public bool $wasProbePending = false;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->server->refresh();
        $this->wasProbePending = $this->probePendingFromMeta($this->server->meta ?? []);
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
            $this->flash_success = __('Monitoring status updated.');
            $this->flash_error = null;
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

    public function collectMetrics(): void
    {
        $this->authorize('view', $this->server);
        $this->metrics_error = null;

        if (! $this->serverOpsReady()) {
            $this->metrics_error = __('Provisioning and SSH must be ready before collecting metrics.');

            return;
        }

        $meta = $this->server->meta ?? [];
        if (! empty($meta['monitoring_probe_pending'])) {
            $this->metrics_error = __('SSH check is still running in the background. Wait a few seconds, then try again.');

            return;
        }

        if (! ($meta['monitoring_ssh_reachable'] ?? false)) {
            $this->metrics_error = __('Dply could not reach this server over SSH the last time we checked. Use Recheck status or fix SSH, then try again.');

            return;
        }

        if (! ($meta['monitoring_python_installed'] ?? false)) {
            $this->metrics_error = __('Python 3 is not installed on this server yet. Install it using the prompt above, then refresh metrics.');

            return;
        }

        set_time_limit(120);

        try {
            app(ServerMetricsCollector::class)->collectAndStore($this->server->fresh());
            $this->server->refresh();
            $this->flash_success = __('Metrics updated.');
            $this->flash_error = null;
        } catch (\Throwable $e) {
            $this->metrics_error = $e->getMessage();
            $this->flash_success = null;
        }
    }

    public function updatedAutoRefresh(bool $value): void
    {
        if ($value) {
            $this->collectMetrics();
        }
    }

    public function render(): View
    {
        $this->server->refresh();

        $latest = ServerMetricSnapshot::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('captured_at')
            ->first();

        $chartLimit = (int) config('server_metrics.chart.max_points', 96);

        $chartSnapshots = ServerMetricSnapshot::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('captured_at')
            ->limit($chartLimit)
            ->get()
            ->sortBy('captured_at')
            ->values();

        $chartFrom = $chartSnapshots->first()?->captured_at;
        $chartTo = $chartSnapshots->last()?->captured_at;
        $tz = config('app.timezone');

        $meta = $this->server->meta ?? [];
        $sshReachable = (bool) ($meta['monitoring_ssh_reachable'] ?? false);
        $pythonInstalled = (bool) ($meta['monitoring_python_installed'] ?? false);
        $probePending = ! empty($meta['monitoring_probe_pending']);

        $guestPush = app(ServerMetricsGuestPushService::class);
        $guestPushCronExpression = $guestPush->isEnabled()
            ? $guestPush->normalizedGuestPushCronExpression()
            : null;

        return view('livewire.servers.workspace-monitor', [
            'latest' => $latest,
            'chartSnapshots' => $chartSnapshots,
            'chartPointLimit' => $chartLimit,
            'chartFrom' => $chartFrom,
            'chartTo' => $chartTo,
            'chartTimezone' => $tz,
            'storedSnapshotCount' => ServerMetricSnapshot::query()
                ->where('server_id', $this->server->id)
                ->count(),
            'opsReady' => $this->serverOpsReady(),
            'isDeployer' => $this->currentUserIsDeployer(),
            'canCollectMetrics' => $this->serverOpsReady()
                && $sshReachable
                && $pythonInstalled
                && ! $probePending,
            'probePending' => $probePending,
            'pollProbeSeconds' => (int) config('server_metrics.ui.poll_probe_seconds', 3),
            'pollRemoteTaskSeconds' => (int) config('server_metrics.ui.poll_remote_task_seconds', 2),
            'pollAutoRefreshSeconds' => (int) config('server_metrics.ui.auto_refresh_seconds', 60),
            'guestPushCronExpression' => $guestPushCronExpression,
        ]);
    }
}
