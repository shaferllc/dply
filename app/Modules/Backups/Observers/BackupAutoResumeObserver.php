<?php

declare(strict_types=1);

namespace App\Modules\Backups\Observers;

use App\Modules\Backups\Console\RunBackupScheduleCommand;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabaseBackup;
use App\Models\SiteFileBackup;

/**
 * Re-enable a paused {@see ServerBackupSchedule} when a backup against the same
 * target completes successfully. Pairs with the auto-pause logic in
 * {@see RunBackupScheduleCommand} — operator fixes whatever
 * was broken (creds, disk space), runs a backup manually, and the schedule
 * resumes itself instead of requiring a separate Resume click.
 *
 * Single observer class registered against both backup models since the lookup
 * shape only differs by target_type.
 */
class BackupAutoResumeObserver
{
    public function updated(ServerDatabaseBackup|SiteFileBackup $backup): void
    {
        // Only act on the moment status flips to completed.
        if (! $backup->wasChanged('status') || $backup->status !== 'completed') {
            return;
        }

        [$targetType, $targetId] = $backup instanceof ServerDatabaseBackup
            ? [ServerBackupSchedule::TARGET_DATABASE, $backup->server_database_id]
            : [ServerBackupSchedule::TARGET_SITE_FILES, $backup->site_id];

        ServerBackupSchedule::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('is_active', false)
            ->get()
            ->each(function (ServerBackupSchedule $schedule): void {
                $schedule->update(['is_active' => true]);
                if ($schedule->server_cron_job_id) {
                    ServerCronJob::query()
                        ->whereKey($schedule->server_cron_job_id)
                        ->update(['enabled' => true]);
                }
            });
    }
}
