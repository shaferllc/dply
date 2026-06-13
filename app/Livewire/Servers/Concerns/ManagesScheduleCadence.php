<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceCron;
use App\Services\Servers\CronExpressionValidator;
use App\Services\Servers\ServerCronSynchronizer;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesScheduleCadence
{


    /**
     * Pause or resume a wrapper-managed scheduler. Mirrors WorkspaceCron's
     * pause/resume — flip `enabled` and push the regenerated crontab so the
     * scheduler actually stops or starts firing on the host. Resume also
     * re-arms the "waiting for first tick" grace window on the heartbeat row
     * (Q20 (b)).
     */
    public function togglePause(string $heartbeatId, ServerCronSynchronizer $synchronizer): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null || $cron === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        $newEnabled = ! $cron->enabled;
        $cron->update(['enabled' => $newEnabled, 'is_synced' => false]);

        // On resume, re-arm the waiting-for-first-tick grace so the operator
        // doesn't get an immediate AMBER chip from accumulated misses while
        // the next tick is in flight.
        if ($newEnabled) {
            $heartbeat->forceFill([
                'first_seen_at' => now(),
                'consecutive_misses' => 0,
            ])->save();
        }

        audit_log(
            $this->server->organization,
            auth()->user(),
            $newEnabled ? 'server.scheduler.resumed' : 'server.scheduler.paused',
            $this->server,
            ['enabled' => ! $newEnabled],
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => (string) $cron->id,
                'enabled' => $newEnabled,
            ],
        );

        try {
            $synchronizer->sync($this->server);
        } catch (\Throwable $e) {
            $this->toastError(__('Scheduler state updated but pushing to crontab failed: :err', ['err' => $e->getMessage()]));

            return;
        }

        $this->emitPanelEvent(
            $newEnabled
                ? __('Scheduler resumed — will tick again within the cadence window.')
                : __('Scheduler paused — no further ticks until you resume.'),
            [],
            'completed',
        );
    }

    public function startEditCadence(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);
        [$heartbeat] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null) {
            return;
        }
        $this->editing_cadence[$heartbeatId] = (string) $heartbeat->cron_expression;
    }

    public function cancelEditCadence(string $heartbeatId): void
    {
        unset($this->editing_cadence[$heartbeatId]);
    }

    public function saveCadence(string $heartbeatId, ServerCronSynchronizer $synchronizer, CronExpressionValidator $validator): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null || $cron === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        $newExpression = trim((string) ($this->editing_cadence[$heartbeatId] ?? ''));
        if (! $validator->isValid($newExpression)) {
            $this->toastError(__('Invalid cron expression.'));

            return;
        }

        $cron->update(['cron_expression' => $newExpression, 'is_synced' => false]);
        // Mirror onto the heartbeat row so the staleness math uses the new
        // cadence on the very next render — without waiting for the agent's
        // next push to refresh it.
        $heartbeat->forceFill(['cron_expression' => $newExpression])->save();

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.cadence_changed',
            $this->server,
            ['cron_expression' => $cron->getOriginal('cron_expression')],
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => (string) $cron->id,
                'cron_expression' => $newExpression,
            ],
        );

        unset($this->editing_cadence[$heartbeatId]);

        try {
            $synchronizer->sync($this->server);
        } catch (\Throwable $e) {
            $this->toastError(__('Cadence updated but pushing to crontab failed: :err', ['err' => $e->getMessage()]));

            return;
        }

        $this->emitPanelEvent(__('Cadence updated to :expr.', ['expr' => $newExpression]), [], 'completed');
    }

    /**
     * Disable Monitoring — Q7 (d). Different from Pause:
     *  - Pause: scheduler stops firing, we keep tracking.
     *  - Disable Monitoring: scheduler keeps firing, we stop tracking.
     *
     * v2B implementation: drops the heartbeat row. v2C will additionally
     * rewrite the cron line to remove the wrapper invocation (this prerequisite
     * doesn't exist yet — wrapper-invoking cron lines are only created by 2C).
     * Per Q20 (c), this is symmetric with Enable creating one.
     */
    public function openDisableMonitoringModal(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);
        $this->disableMonitoringHeartbeatId = $heartbeatId;
        $this->showDisableMonitoringModal = true;
    }

    public function closeDisableMonitoringModal(): void
    {
        $this->showDisableMonitoringModal = false;
        $this->disableMonitoringHeartbeatId = null;
    }

    public function confirmDisableMonitoring(): void
    {
        if ($this->disableMonitoringHeartbeatId === null) {
            return;
        }

        $heartbeatId = $this->disableMonitoringHeartbeatId;
        $this->closeDisableMonitoringModal();
        $this->disableMonitoring($heartbeatId);
    }

    public function disableMonitoring(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.monitoring_disabled',
            $this->server,
            null,
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => $cron?->id,
                'scheduler_kind' => $heartbeat->scheduler_kind,
            ],
        );

        $heartbeat->delete();

        $this->toastSuccess(__('Monitoring stopped. The scheduler keeps running; we won\'t track or alert on it anymore. Re-enable from the same site to start over.'));
    }
}
