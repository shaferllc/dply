<?php

declare(strict_types=1);

namespace App\Modules\Backups\Observers;

use App\Models\ServerBackupSchedule;
use App\Models\ServerDatabaseBackup;
use App\Models\SiteFileBackup;
use App\Notifications\BackupFailureNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Sends an email to org admins when a backup transitions to 'failed' AND there's
 * an opted-in {@see ServerBackupSchedule} for the same target. Operates only on
 * the status flip — older 'failed' rows are not re-notified.
 *
 * Pairs with {@see BackupAutoResumeObserver}: failures notify, successes resume.
 */
class BackupFailureNotifyObserver
{
    public function updated(ServerDatabaseBackup|SiteFileBackup $backup): void
    {
        if (! $backup->wasChanged('status') || $backup->status !== 'failed') {
            return;
        }

        [$targetType, $targetId] = $backup instanceof ServerDatabaseBackup
            ? [ServerBackupSchedule::TARGET_DATABASE, $backup->server_database_id]
            : [ServerBackupSchedule::TARGET_SITE_FILES, $backup->site_id];

        $schedule = ServerBackupSchedule::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('notify_on_failure', true)
            ->first();
        if ($schedule === null) {
            return;
        }

        $server = $schedule->server;
        $org = $server?->organization;
        if ($org === null) {
            return;
        }

        $admins = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new BackupFailureNotification(
            schedule: $schedule,
            errorMessage: (string) ($backup->error_message ?? ''),
            serverName: (string) ($server->name ?? ''),
        ));
    }
}
