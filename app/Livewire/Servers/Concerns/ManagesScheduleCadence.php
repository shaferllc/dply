<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceCron;
use App\Modules\RemoteCli\Services\WpCli;
use App\Services\Servers\CronExpressionValidator;
use App\Services\Servers\SchedulerWrapperScript;
use App\Services\Servers\ServerCronSynchronizer;
use Throwable;

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
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
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
     * Drops the heartbeat row and unwraps the cron line back to the bare
     * command. Both halves matter: a still-wrapped line keeps pushing ticks,
     * and the ingest recreates the heartbeat within a minute.
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

        if ($cron !== null) {
            $cron->update(['command' => SchedulerWrapperScript::unwrap((string) $cron->command), 'is_synced' => false]);
            try {
                app(ServerCronSynchronizer::class)->sync($this->server);
            } catch (Throwable $e) {
                $this->toastError(__('Could not push the unwrapped line to the crontab: :err', ['err' => $e->getMessage()]));

                return;
            }
        }

        $heartbeat->delete();

        $this->toastSuccess(__('Monitoring stopped. The scheduler keeps running; we won\'t track or alert on it anymore. Enable monitoring on the same row to start over.'));
    }

    /**
     * Turn a scheduler off: its cron entry and heartbeat go. A WordPress site
     * gets its HTTP wp-cron back first, so it is never left with neither.
     */
    public function disableScheduler(string $heartbeatId, ServerCronSynchronizer $synchronizer, WpCli $wpcli): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        $site = $heartbeat->site;
        if ($site !== null && $cron !== null && str_contains((string) $cron->command, 'cron event run')) {
            try {
                $wpcli->run($site, 'config delete', ['DISABLE_WP_CRON', '--type=constant'], auth()->user());
            } catch (Throwable $e) {
                $this->toastError(__('Could not turn wp-cron back on, so the scheduler stays: :err', ['err' => $e->getMessage()]));

                return;
            }

            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['wp_cron'] = array_merge(
                is_array($meta['wp_cron'] ?? null) ? $meta['wp_cron'] : [],
                ['handler' => 'wp_cron', 'switched_at' => now()->toISOString(), 'error' => null],
            );
            $site->forceFill(['meta' => $meta])->save();
        }

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.disabled',
            $this->server,
            null,
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => $cron?->id,
                'scheduler_kind' => $heartbeat->scheduler_kind,
            ],
        );

        if ($cron !== null) {
            // Disable, sync, then delete: sync() returns early when a server
            // has no jobs left, which would leave the old line in place.
            $cron->update(['enabled' => false, 'is_synced' => false]);
            try {
                $synchronizer->sync($this->server);
            } catch (Throwable $e) {
                $this->toastError(__('Scheduler disabled in dply but pushing to crontab failed: :err', ['err' => $e->getMessage()]));

                return;
            }
            $cron->delete();
        }

        $heartbeat->delete();

        $this->emitPanelEvent(__('Scheduler disabled — its cron entry is gone.'), [], 'completed');
    }
}
