<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\RunServerMonitoringProbeJob;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Owns the monitoring-probe pending flag and its dispatch.
 *
 * Extracted from {@see \App\Livewire\Servers\Concerns\ManagesMonitorProbe} so
 * the REST surface can queue the same probe the Metrics page queues, with one
 * copy of the stale-flag release rule.
 */
final class ServerMonitoringProbeQueuer
{
    /**
     * Queue an SSH probe (python3 check). Never blocks on SSH.
     *
     * @return bool True when a probe was dispatched; false when one is already
     *              pending, so callers can report "already running".
     */
    public function queue(Server $server): bool
    {
        $this->releaseStalePending($server);

        $meta = $server->meta ?? [];
        if (! empty($meta['monitoring_probe_pending'])) {
            return false;
        }

        $meta['monitoring_probe_pending'] = true;
        $meta['monitoring_probe_pending_at'] = now()->toIso8601String();
        $server->update(['meta' => $meta]);
        $server->refresh();

        $pending = RunServerMonitoringProbeJob::dispatch($server->id);
        $queue = config('server_metrics.probe.queue');
        if (is_string($queue) && $queue !== '') {
            $pending->onQueue($queue);
        }

        return true;
    }

    /**
     * A probe finishes in seconds; anything older than the stale window means
     * the job was lost/killed before clearing the flag (e.g. a deploy restarted
     * Horizon mid-probe). Release it so the next poll re-dispatches instead of
     * spinning "still running" for many minutes.
     *
     * Refreshes `$server` in place when it clears the flag.
     */
    public function releaseStalePending(Server $server): void
    {
        $meta = $server->meta ?? [];
        if (empty($meta['monitoring_probe_pending']) || empty($meta['monitoring_probe_pending_at'])) {
            return;
        }

        try {
            $at = Carbon::parse((string) $meta['monitoring_probe_pending_at']);
        } catch (Throwable) {
            $this->clearPending($server, $meta);

            return;
        }

        $staleSeconds = (int) config('server_metrics.probe.stale_pending_seconds', 120);
        if ($at->lt(now()->subSeconds($staleSeconds))) {
            $this->clearPending($server, $meta);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function clearPending(Server $server, array $meta): void
    {
        unset($meta['monitoring_probe_pending'], $meta['monitoring_probe_pending_at']);
        $server->update(['meta' => $meta]);
        $server->refresh();
    }
}
