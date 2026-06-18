<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RunServerMonitoringProbeJob;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesMonitorProbe
{


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

        // A probe finishes in seconds; anything older than the stale window means
        // the job was lost/killed before clearing the flag (e.g. a deploy
        // restarted Horizon mid-probe). Release it so the next poll re-dispatches
        // instead of spinning "still running" for many minutes.
        $staleSeconds = (int) config('server_metrics.probe.stale_pending_seconds', 120);
        if ($at->lt(now()->subSeconds($staleSeconds))) {
            unset($meta['monitoring_probe_pending'], $meta['monitoring_probe_pending_at']);
            $server->update(['meta' => $meta]);
            $this->server = $server->fresh();
        }
    }
}
