<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabaseBackup;
use App\Models\SiteFileBackup;
use App\Services\Servers\DatabaseBackupExporter;
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

    /** Auto-disable a schedule after this many consecutive failures (last N backups all failed). */
    private const FAILURE_AUTO_PAUSE_THRESHOLD = 3;

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

        if ($this->shouldAutoPause($schedule)) {
            $schedule->update(['is_active' => false]);
            if ($schedule->server_cron_job_id) {
                ServerCronJob::query()
                    ->whereKey($schedule->server_cron_job_id)
                    ->update(['enabled' => false]);
            }
            $this->warn('Schedule auto-paused after '.self::FAILURE_AUTO_PAUSE_THRESHOLD.' consecutive failures.');

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

    /**
     * True when the last N backups for this schedule's target are ALL failed.
     * Operators get a clean signal that the destination/credentials are broken
     * instead of the queue spamming dead jobs forever.
     */
    private function shouldAutoPause(ServerBackupSchedule $schedule): bool
    {
        $threshold = self::FAILURE_AUTO_PAUSE_THRESHOLD;

        $recent = match ($schedule->target_type) {
            ServerBackupSchedule::TARGET_DATABASE => ServerDatabaseBackup::query()
                ->where('server_database_id', $schedule->target_id)
                ->orderByDesc('created_at')
                ->limit($threshold)
                ->pluck('status'),
            ServerBackupSchedule::TARGET_SITE_FILES => SiteFileBackup::query()
                ->where('site_id', $schedule->target_id)
                ->orderByDesc('created_at')
                ->limit($threshold)
                ->pluck('status'),
            default => collect(),
        };

        return $recent->count() >= $threshold && $recent->every(fn ($s) => $s === 'failed');
    }

    private function dispatchDatabaseBackup(ServerBackupSchedule $schedule): void
    {
        $schedule->loadMissing('server');

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $schedule->target_id,
            'user_id' => null,
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $schedule->server,
            $schedule->backup_configuration_id,
        );

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
