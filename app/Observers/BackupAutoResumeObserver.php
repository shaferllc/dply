<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabaseBackup;
use App\Models\SiteFileBackup;
use Illuminate\Database\Eloquent\Model;

/**
 * Re-enable a paused {@see ServerBackupSchedule} when a backup against the same
 * target completes successfully. Pairs with the auto-pause logic in
 * {@see \App\Console\Commands\RunBackupScheduleCommand} — operator fixes whatever
 * was broken (creds, disk space), runs a backup manually, and the schedule
 * resumes itself instead of requiring a separate Resume click.
 *
 * Single observer class registered against both backup models since the lookup
 * shape only differs by target_type.
 */
class BackupAutoResumeObserver
{
    public function updated(Model $backup): void
    {
        // Only act on the moment status flips to completed.
        if (! $backup->wasChanged('status') || $backup->status !== 'completed') {
            return;
        }

        [$targetType, $targetId] = match (true) {
            $backup instanceof ServerDatabaseBackup => [ServerBackupSchedule::TARGET_DATABASE, $backup->server_database_id],
            $backup instanceof SiteFileBackup => [ServerBackupSchedule::TARGET_SITE_FILES, $backup->site_id],
            default => [null, null],
        };

        if ($targetType === null) {
            return;
        }

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
