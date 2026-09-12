<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RunSchedulerNowJob;
use App\Jobs\SetSchedulerOutputCaptureJob;
use App\Livewire\Servers\WorkspaceDaemons;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SchedulerCardsBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesScheduleRuns
{
    /**
     * Top-level Run-now — fires `schedule:run` once via SSH. Per Q15:
     *  - Refuses a second click while one is in flight (Q15 (e))
     *  - Always audits (Q15 (f))
     *  - Streams output through ConsoleAction (Q15 (c1))
     *  - 5-minute hard timeout (Q15 (d))
     *  - Coordinates with the wrapper's advisory file lock (Q15 (b1))
     *
     * The job itself (the SSH + lock + stream piece) lands here as a dispatch
     * stub for 2B; full streaming integration lives in the job class and can
     * iterate independently. This method only kicks the job and records intent.
     */
    public function runNow(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null || $cron === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        if (in_array($heartbeatId, $this->run_now_in_flight, true)) {
            $this->toastError(__('A Run now is already in flight for this scheduler. Watch the activity banner.'));

            return;
        }

        $this->run_now_in_flight[] = $heartbeatId;

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.run_now',
            $this->server,
            null,
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => (string) $cron->id,
                'scheduler_kind' => $heartbeat->scheduler_kind,
            ],
        );

        $runId = (string) Str::ulid();
        $this->scheduler_run_id = $runId;
        $this->scheduler_run_busy = true;
        $this->scheduler_run_cache_key = RunSchedulerNowJob::cacheKey($runId);

        RunSchedulerNowJob::dispatch(
            $this->server->id,
            $heartbeat->id,
            (string) auth()->id(),
            $runId,
        );

        $this->emitPanelEvent(
            __('Run now queued for :site scheduler.', ['site' => $heartbeat->site->name ?? $heartbeatId]),
            [__('Running schedule:run on the server… (5-minute timeout)')],
            'running',
        );
    }

    /**
     * Poll the cached output of the in-flight Run-now job (mirrors
     * WorkspaceDaemons::pollDaemonOperation). Wired to wire:poll while busy.
     */
    public function pollSchedulerRun(): void
    {
        if ($this->scheduler_run_cache_key === null || ! $this->scheduler_run_busy) {
            return;
        }

        $payload = Cache::get($this->scheduler_run_cache_key);
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? 'running');
        if (! in_array($status, ['done', 'failed'], true)) {
            return;
        }

        $output = (string) ($payload['output'] ?? '');
        $lines = array_values(array_filter(explode("\n", $output)));
        $this->scheduler_run_busy = false;
        $this->scheduler_run_id = null;
        $this->scheduler_run_cache_key = null;
        $this->run_now_in_flight = [];

        // An enable reports per row: its failed checks render under the site.
        $siteId = $payload['site_id'] ?? null;
        if (is_string($siteId)) {
            $this->enabling_site_id = null;
            if ($status === 'failed') {
                $checks = array_values(array_filter(
                    (array) ($payload['checks'] ?? []),
                    fn ($check): bool => is_array($check) && ($check['status'] ?? '') !== 'pass',
                ));
                $this->enable_failures[$siteId] = $checks !== []
                    ? $checks
                    : [['key' => 'error', 'status' => 'fail', 'message' => $output]];
            }
        }

        $this->emitPanelEvent(
            $status === 'done' ? __('Done.') : __('Operation failed.'),
            $lines,
            $status === 'done' ? 'completed' : 'failed',
        );
    }

    /**
     * Per-scheduler output capture toggle (PR2). Flips the DB column optimistically
     * and queues an SSH job to touch/remove the on-box control file (and lazily
     * re-push a capture-capable wrapper when enabling).
     */
    public function toggleOutputCapture(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);

        $heartbeat = ServerSchedulerHeartbeat::query()
            ->where('server_id', $this->server->id)
            ->whereKey($heartbeatId)
            ->first();
        if ($heartbeat === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        $enabled = ! $heartbeat->output_capture_enabled;
        $heartbeat->forceFill(['output_capture_enabled' => $enabled])->save();

        $runId = (string) Str::ulid();
        $this->scheduler_run_id = $runId;
        $this->scheduler_run_busy = true;
        $this->scheduler_run_cache_key = SetSchedulerOutputCaptureJob::cacheKey($runId);

        SetSchedulerOutputCaptureJob::dispatch($this->server->id, $heartbeat->id, $enabled, $runId);

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.capture_'.($enabled ? 'enabled' : 'disabled'),
            $this->server,
            null,
            ['heartbeat_id' => $heartbeat->id, 'site_id' => $heartbeat->site_id],
        );

        $this->emitPanelEvent(
            $enabled
                ? __('Enabling output capture — pushing control file to the server…')
                : __('Disabling output capture — removing control file…'),
            [],
            'running',
        );
    }

    /**
     * Logs tab — tail the system cron daemon log. Analog to the daemons
     * "supervisord daemon log" card; proves cron itself is firing entries.
     * Distros differ, so try journalctl (cron, then crond) then syslog.
     */
    public function loadCronDaemonLog(ExecuteRemoteTaskOnServer $exec): void
    {
        $this->authorize('view', $this->server);
        $this->cron_daemon_log_body = null;

        if (! $this->serverOpsReady()) {
            $this->cron_daemon_log_body = __('Server is not ready for SSH.');

            return;
        }

        $cmd = 'journalctl -u cron --no-pager -n 200 2>/dev/null'
            .' || journalctl -u crond --no-pager -n 200 2>/dev/null'
            .' || grep -iE "CRON|cron" /var/log/syslog 2>/dev/null | tail -n 200'
            .' || echo "No cron daemon log available."';

        try {
            $out = $exec->runInlineBash($this->server->fresh(), 'scheduler-cron-log', $cmd, timeoutSeconds: 20, asRoot: false);
            $body = trim((string) $out->buffer);
            $this->cron_daemon_log_body = $body !== '' ? $body : __('(cron daemon log is empty)');
        } catch (\Throwable $e) {
            $this->cron_daemon_log_body = $e->getMessage();
        }
    }

    /**
     * Resolve a heartbeat by id + its companion cron row (joined on
     * server_id + site_id + scheduler_kind via the same convention the cards
     * builder uses). Both must belong to the current server — defensive
     * against URL tampering.
     *
     * @return array{0: ?ServerSchedulerHeartbeat, 1: ?ServerCronJob}
     */
    private function resolveHeartbeatAndCron(string $heartbeatId): array
    {
        $hb = ServerSchedulerHeartbeat::query()
            ->where('server_id', $this->server->id)
            ->whereKey($heartbeatId)
            ->first();
        if ($hb === null) {
            return [null, null];
        }

        // Same match the cards builder uses, so the page and the actions work
        // on the same row — wrapped custom commands included.
        return [$hb, SchedulerCardsBuilder::cronFor($hb)];
    }
}
