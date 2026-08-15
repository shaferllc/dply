<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\ServerMonitoringProbeQueuer;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 *
 * The pending-flag bookkeeping itself lives in
 * {@see \App\Services\Servers\ServerMonitoringProbeQueuer} so the REST surface
 * queues the identical probe.
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
     *
     * On a production-data mirror there is no local SSH key, so the probe is
     * asked of the control plane that owns the host instead of being dispatched
     * here (where it could only fail).
     */
    public function queueMonitoringProbe(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            return;
        }

        $server = $this->server->fresh();
        if (app(ServerMonitoringProbeQueuer::class)->queue($server)) {
            $this->wasProbePending = true;
        }
        $this->server = $server->fresh();
    }

    public function syncMonitoringProbeStatus(): void
    {
        $this->authorize('view', $this->server);
        $this->server->refresh();

        app(ServerMonitoringProbeQueuer::class)->releaseStalePending($this->server);
        $this->server->refresh();

        $pending = $this->probePendingFromMeta($this->server->meta ?? []);
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
}
