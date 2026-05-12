<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Models\ServerBackupSchedule;
use App\Models\ServerDatabaseBackup;
use App\Models\SiteFileBackup;
use Illuminate\Console\Command;

/**
 * Invoked by the cron entry that materializes a {@see ServerBackupSchedule}.
 * The cron line shape is `php artisan dply:run-backup-schedule {schedule}` so
 * the schedule row is the source of truth — operators can edit the cadence on
 * the schedule and we don't have to rewrite the cron line.
 */
class RunBackupScheduleCommand extends Command
{
    protected $signature = 'dply:run-backup-schedule {schedule}';

    protected $description = 'Create a pending backup row and dispatch the export job for the given ServerBackupSchedule.';

    public function handle(): int
    {
        $schedule = ServerBackupSchedule::query()->find((string) $this->argument('schedule'));
        if ($schedule === null) {
            $this->error('Schedule not found.');

            return self::FAILURE;
        }

        if (! $schedule->is_active) {
            $this->info('Schedule is inactive — skipping.');

            return self::SUCCESS;
        }

        match ($schedule->target_type) {
            ServerBackupSchedule::TARGET_DATABASE => $this->dispatchDatabaseBackup($schedule),
            ServerBackupSchedule::TARGET_SITE_FILES => $this->dispatchSiteFilesBackup($schedule),
            default => $this->error('Unknown target type: '.$schedule->target_type),
        };

        $schedule->update(['last_run_at' => now()]);

        return self::SUCCESS;
    }

    private function dispatchDatabaseBackup(ServerBackupSchedule $schedule): void
    {
        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $schedule->target_id,
            'user_id' => null,
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        ExportServerDatabaseBackupJob::dispatch($backup->id);
        $this->info('Dispatched database backup '.$backup->id);
    }

    private function dispatchSiteFilesBackup(ServerBackupSchedule $schedule): void
    {
        $backup = SiteFileBackup::create([
            'site_id' => $schedule->target_id,
            'user_id' => null,
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);

        ExportSiteFileBackupJob::dispatch($backup->id);
        $this->info('Dispatched site files backup '.$backup->id);
    }
}
