<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\BackupSchedule;
use App\Models\ServerDatabase;
use App\Models\Site;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerBackupSchedules
{


    public function addSchedule(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'new_target_type' => 'required|in:database,site_files',
            'new_target_id' => 'required|string',
            'new_cron_expression' => 'required|string|max:64',
            'new_backup_configuration_id' => 'nullable|string',
        ]);

        // In site-dedicated context the target must belong to the focused site
        // (the picker only offers this site + its linked databases) — enforce it
        // server-side so a crafted request can't schedule another site's backup.
        $siteScope = $this->siteDedicatedContext ? $this->context_site_id : null;

        $exists = match ($this->new_target_type) {
            BackupSchedule::TARGET_DATABASE => ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->when($siteScope !== null, fn ($q) => $q->where('site_id', $siteScope))
                ->whereKey($this->new_target_id)
                ->exists(),
            BackupSchedule::TARGET_SITE_FILES => Site::query()
                ->where('server_id', $this->server->id)
                ->when($siteScope !== null, fn ($q) => $q->whereKey($siteScope))
                ->whereKey($this->new_target_id)
                ->exists(),
            default => false,
        };
        if (! $exists) {
            $this->toastError($siteScope !== null
                ? __('Pick a target that belongs to this site.')
                : __('Target not found on this server.'));

            return;
        }

        $schedule = BackupSchedule::create([
            'server_id' => $this->server->id,
            'target_type' => $this->new_target_type,
            'target_id' => $this->new_target_id,
            'backup_configuration_id' => $this->new_backup_configuration_id ?: null,
            'cron_expression' => $this->new_cron_expression,
            'is_active' => true,
        ]);

        // No cron row. This used to mint a `system_managed` ServerCronJob whose
        // command pointed at the control plane's artisan binary — but
        // ServerCronSynchronizer excludes system_managed rows from the server's
        // crontab, so that line executed nowhere. The schedule row is the only
        // source of truth now; DispatchDueBackupSchedulesCommand ticks it from
        // the control plane. See docs/adr/backups-as-a-product.md, decision 14.

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.created', $schedule, null, [
                'target_type' => $schedule->target_type,
                'target_id' => $schedule->target_id,
                'cron_expression' => $schedule->cron_expression,
            ]);
        }

        $this->dispatchBackupNotification('schedule_created', [$schedule->targetLabel()], [
            'schedule_id' => (string) $schedule->id,
            'target_type' => $schedule->target_type,
            'cron_expression' => $schedule->cron_expression,
        ]);

        $this->reset(['new_target_id', 'new_backup_configuration_id']);
        $this->new_cron_expression = '0 3 * * *';
        $this->toastSuccess(__('Backup schedule added.'));
    }

    public function deleteSchedule(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = BackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.deleted', $schedule, [
                'cron_expression' => $schedule->cron_expression,
            ], null);
        }

        $scheduleLabel = $schedule->targetLabel();
        $scheduleCron = $schedule->cron_expression;
        $schedule->delete();
        $this->dispatchBackupNotification('schedule_deleted', [$scheduleLabel], [
            'schedule_id' => $scheduleId,
            'cron_expression' => $scheduleCron,
        ]);
        $this->toastSuccess(__('Backup schedule removed.'));
    }

    public function startEditSchedule(string $scheduleId): void
    {
        $schedule = BackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }
        $this->editing_schedules[$scheduleId] = $schedule->cron_expression;
    }

    public function cancelEditSchedule(string $scheduleId): void
    {
        unset($this->editing_schedules[$scheduleId]);
    }

    public function saveScheduleCadence(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $newCron = trim((string) ($this->editing_schedules[$scheduleId] ?? ''));
        if ($newCron === '' || strlen($newCron) > 64) {
            $this->toastError(__('Invalid cron expression.'));

            return;
        }

        $schedule = BackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $oldCron = $schedule->cron_expression;
        $schedule->update(['cron_expression' => $newCron]);

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                'backup.schedule.cadence_updated',
                $schedule,
                ['cron_expression' => $oldCron],
                ['cron_expression' => $newCron],
            );
        }

        $this->dispatchBackupNotification('schedule_updated', [$schedule->targetLabel()], [
            'schedule_id' => (string) $schedule->id,
            'change' => 'cadence',
            'cron_expression' => $newCron,
            'previous_cron_expression' => $oldCron,
        ]);

        unset($this->editing_schedules[$scheduleId]);
        $this->toastSuccess(__('Schedule updated.'));
    }

    /**
     * Pause/resume a schedule. `is_active` is the whole mechanism — the
     * control-plane dispatcher only considers active rows — so resume is one
     * click and the cadence is preserved either way.
     */
    public function toggleSchedule(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = BackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $newActive = ! $schedule->is_active;
        $schedule->update(['is_active' => $newActive]);

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                $newActive ? 'backup.schedule.resumed' : 'backup.schedule.paused',
                $schedule,
            );
        }

        $this->dispatchBackupNotification('schedule_updated', [$schedule->targetLabel()], [
            'schedule_id' => (string) $schedule->id,
            'change' => $newActive ? 'resumed' : 'paused',
        ]);

        $this->toastSuccess($newActive ? __('Schedule resumed.') : __('Schedule paused.'));
    }
}
