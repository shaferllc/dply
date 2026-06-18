<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Models\ConsoleAction;
use App\Models\ServerBackupSchedule;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Notifications\BackupFailureNotification;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerBackupRuns
{


    public function setBackupsWorkspaceTab(string $tab): void
    {
        $this->backups_workspace_tab = in_array($tab, ['overview', 'schedules', 'history', 'notifications'], true) ? $tab : 'overview';
    }

    /**
     * Seed a console-action row for an on-demand backup run and arm the
     * session-scoped, one-shot completion watch. Subject is THIS server (where
     * the banner renders), the originating tab is whatever the operator clicked
     * from. Returns the run id to hand to the export job.
     */
    private function startBackupConsoleRun(string $kind, string $label, string $backupId, string $type): string
    {
        $runId = $this->seedConsoleActionRun($this->server, $kind, $label);

        $this->watchedBackupRunId = $runId;
        $this->watchedBackupId = $backupId;
        $this->watchedBackupType = $type;
        $this->originatingBackupTab = $this->backups_workspace_tab;

        // A fresh run supersedes any prior highlight so an old flash doesn't
        // linger over the wrong row.
        $this->highlightBackupId = null;
        $this->highlightBackupType = null;

        return $runId;
    }

    /**
     * Poll hook (active only while {@see $watchedBackupRunId} is set) for the
     * one-shot guard-jump. On success → jump to History + flash the row, but
     * only if still on the originating tab (no focus hijack). On failure → an
     * error toast; the banner already streams the error, so no jump. A vanished
     * or dismissed run just disarms the watch.
     */
    public function pollBackupRun(): void
    {
        if ($this->watchedBackupRunId === null) {
            return;
        }

        $run = ConsoleAction::query()
            ->whereKey($this->watchedBackupRunId)
            ->first(['id', 'status', 'dismissed_at']);

        // Dismissed mid-run (operator said "done watching") or gone → disarm.
        if ($run === null || $run->dismissed_at !== null) {
            $this->disarmBackupWatch();

            return;
        }

        if (! in_array($run->status, [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], true)) {
            return; // still queued/running
        }

        $stillOnOriginatingTab = $this->backups_workspace_tab === $this->originatingBackupTab;

        if ($run->status === ConsoleAction::STATUS_COMPLETED) {
            if ($stillOnOriginatingTab) {
                // Jump + flash; the History row's x-init scrolls itself into view.
                $this->highlightBackupId = $this->watchedBackupId;
                $this->highlightBackupType = $this->watchedBackupType;
                $this->backups_workspace_tab = 'history';
            } else {
                // They navigated away — don't yank them; just confirm.
                $this->toastSuccess(__('Backup complete — it’s in History.'));
            }
        } else { // failed
            $this->toastError(__('Backup failed — see the console banner for details.'));
        }

        $this->disarmBackupWatch();
    }

    private function disarmBackupWatch(): void
    {
        $this->watchedBackupRunId = null;
        $this->watchedBackupId = null;
        $this->watchedBackupType = null;
        $this->originatingBackupTab = null;
    }

    public function runDatabaseBackup(): void
    {
        $this->authorize('update', $this->server);

        // Site context only runs databases linked to the focused site.
        $siteScope = $this->siteDedicatedContext ? $this->context_site_id : null;

        $database = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->when($siteScope !== null, fn ($q) => $q->where('site_id', $siteScope))
            ->whereKey($this->run_database_id)
            ->first();

        // Fall back to a database hosted on another server that a site here
        // attaches to. Its backup still runs on its OWN home server (the export
        // job dumps where $db->server lives), so this is purely a surfacing
        // convenience — same org + binding constraints enforced by the helper.
        if ($database === null) {
            $database = $this->remoteAttachedDatabases()->firstWhere('id', $this->run_database_id);
        }

        if ($database === null) {
            $this->toastError(__('Pick a database to back up.'));

            return;
        }

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $this->server,
            $this->run_database_backup_configuration_id !== '' ? $this->run_database_backup_configuration_id : null,
        );

        $runId = $this->startBackupConsoleRun(
            'backup_database',
            __('Database — :name', ['name' => $database->name]),
            (string) $backup->id,
            'database',
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id, $runId);

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.database.run_dispatched', $this->server, null, [
                'backup_id' => (string) $backup->id,
                'database_id' => (string) $database->id,
                'database_name' => $database->name,
            ]);
        }

        $this->dispatchBackupNotification('run_started', [__('Database — :name', ['name' => $database->name])], [
            'backup_type' => 'database',
            'backup_id' => (string) $backup->id,
            'database_id' => (string) $database->id,
        ]);

        $this->run_database_id = '';
        // No "queued" toast — the console banner now streams queued → running.
        $this->dispatch('dply-console-action-focus');
    }

    public function runSiteFilesBackup(): void
    {
        $this->authorize('update', $this->server);

        // Site context can only run the focused site (the second whereKey pins
        // it, so a crafted run_site_id for another site finds no match).
        $siteScope = $this->siteDedicatedContext ? $this->context_site_id : null;

        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->when($siteScope !== null, fn ($q) => $q->whereKey($siteScope))
            ->whereKey($this->run_site_id)
            ->first();

        if ($site === null) {
            $this->toastError(__('Pick a site to back up.'));

            return;
        }

        $backup = SiteFileBackup::create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);

        $runId = $this->startBackupConsoleRun(
            'backup_site_files',
            __('Site files — :name', ['name' => $site->name]),
            (string) $backup->id,
            'site_files',
        );

        ExportSiteFileBackupJob::dispatch($backup->id, $runId);

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.site_files.run_dispatched', $site, null, [
                'backup_id' => (string) $backup->id,
                'site_id' => (string) $site->id,
                'site_name' => $site->name,
            ]);
        }

        $this->dispatchBackupNotification('run_started', [__('Site files — :name', ['name' => $site->name])], [
            'backup_type' => 'site_files',
            'backup_id' => (string) $backup->id,
            'site_id' => (string) $site->id,
        ]);

        $this->run_site_id = '';
        // No "queued" toast — the console banner now streams queued → running.
        $this->dispatch('dply-console-action-focus');
    }

    /**
     * Kick off the export job for a schedule's target immediately — same path the
     * cron tick takes. Useful for testing a freshly-added schedule or after fixing
     * destination credentials.
     */
    /**
     * Fire a one-shot {@see BackupFailureNotification} with the
     * test marker so operators can validate their email/recipient setup without
     * inducing an actual backup failure.
     */
    public function sendTestAlert(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $org = $this->server->organization;
        if ($org === null) {
            $this->toastError(__('No organization for this server.'));

            return;
        }

        $admins = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
        if ($admins->isEmpty()) {
            $this->toastError(__('No org admins to send to.'));

            return;
        }

        Notification::send($admins, new BackupFailureNotification(
            schedule: $schedule,
            errorMessage: __('Test alert triggered by :user.', ['user' => auth()->user()?->email ?? 'operator']),
            serverName: (string) ($this->server->name ?? ''),
            isTest: true,
        ));

        audit_log($org, auth()->user(), 'backup.schedule.test_alert', $schedule);

        $this->toastSuccess(__('Test alert sent to :n admin(s).', ['n' => $admins->count()]));
    }

    public function toggleNotifyOnFailure(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $newValue = ! $schedule->notify_on_failure;
        $schedule->update(['notify_on_failure' => $newValue]);

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                $newValue ? 'backup.schedule.notify_enabled' : 'backup.schedule.notify_disabled',
                $schedule,
            );
        }

        $this->toastSuccess($newValue ? __('Failure alerts enabled.') : __('Failure alerts disabled.'));
    }

    public function runScheduleNow(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        match ($schedule->target_type) {
            ServerBackupSchedule::TARGET_DATABASE => $this->dispatchScheduleDatabase($schedule),
            ServerBackupSchedule::TARGET_SITE_FILES => $this->dispatchScheduleSiteFiles($schedule),
            default => $this->toastError(__('Unknown target type.')),
        };

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.run_now', $schedule);
        }
    }
}
