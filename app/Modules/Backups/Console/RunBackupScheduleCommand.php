<?php

declare(strict_types=1);

namespace App\Modules\Backups\Console;

use App\Jobs\ExportRedisSnapshotJob;
use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Models\BackupSchedule;
use App\Models\RedisSnapshot;
use App\Models\ServerDatabaseBackup;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use Illuminate\Console\Command;

/**
 * Runs one {@see BackupSchedule}, whatever it targets — database dump, site file
 * archive, or cache RDB snapshot. Server images join in M2.
 *
 * Invoked by {@see DispatchDueBackupSchedulesCommand} when the schedule comes
 * due, and by an operator directly for a one-off. Absorbed the old
 * `dply:run-redis-snapshot-schedule`, which was a copy of this file operating on
 * a copy of the schedule table (docs/adr/backups-as-a-product.md, decision 8).
 */
class RunBackupScheduleCommand extends Command
{
    protected $signature = 'dply:run-backup-schedule {schedule}';

    protected $description = 'Create a pending backup row and dispatch the export job for the given BackupSchedule.';

    /** Auto-disable a schedule after this many consecutive failures (last N backups all failed). */
    private const FAILURE_AUTO_PAUSE_THRESHOLD = 3;

    public function handle(): int
    {
        $schedule = BackupSchedule::query()->find((string) $this->argument('schedule'));
        if ($schedule === null) {
            $this->error('Schedule not found.');

            return self::FAILURE;
        }

        if (! $schedule->is_active) {
            $this->info('Schedule is inactive — skipping.');

            return self::SUCCESS;
        }

        // Standing automation (scheduled backups) stops at hard pause to cap
        // dply's running cost on a non-paying org. The schedule stays active —
        // it resumes on its own next tick once the org pays — and manual backups
        // (dispatched directly from the UI) remain available throughout.
        $schedule->loadMissing('server.organization');
        $organization = $schedule->server?->organization;
        if ($organization !== null && ! $organization->permitsStandingAutomation()) {
            $this->info('Owning organization is hard-paused — skipping scheduled backup (manual backups remain available).');

            return self::SUCCESS;
        }

        if ($this->shouldAutoPause($schedule)) {
            $schedule->update(['is_active' => false]);
            $this->warn('Schedule auto-paused after '.self::FAILURE_AUTO_PAUSE_THRESHOLD.' consecutive failures.');

            return self::SUCCESS;
        }

        match ($schedule->target_type) {
            BackupSchedule::TARGET_DATABASE => $this->dispatchDatabaseBackup($schedule),
            BackupSchedule::TARGET_SITE_FILES => $this->dispatchSiteFilesBackup($schedule),
            BackupSchedule::TARGET_CACHE => $this->dispatchCacheSnapshot($schedule),
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
    private function shouldAutoPause(BackupSchedule $schedule): bool
    {
        $threshold = self::FAILURE_AUTO_PAUSE_THRESHOLD;

        $recent = match ($schedule->target_type) {
            BackupSchedule::TARGET_DATABASE => ServerDatabaseBackup::query()
                ->where('server_database_id', $schedule->target_id)
                ->orderByDesc('created_at')
                ->limit($threshold)
                ->pluck('status'),
            BackupSchedule::TARGET_SITE_FILES => SiteFileBackup::query()
                ->where('site_id', $schedule->target_id)
                ->orderByDesc('created_at')
                ->limit($threshold)
                ->pluck('status'),
            BackupSchedule::TARGET_CACHE => RedisSnapshot::query()
                ->where('server_cache_service_id', $schedule->target_id)
                ->orderByDesc('created_at')
                ->limit($threshold)
                ->pluck('status'),
            default => collect(),
        };

        return $recent->count() >= $threshold && $recent->every(fn ($s) => $s === 'failed');
    }

    /**
     * RDB snapshot of a cache service. Ported from the retired
     * `dply:run-redis-snapshot-schedule`; the storage kind is always the
     * configured destination because a cache schedule requires one.
     */
    private function dispatchCacheSnapshot(BackupSchedule $schedule): void
    {
        $schedule->loadMissing('server');

        $snapshot = RedisSnapshot::query()->create([
            'server_id' => $schedule->server_id,
            'server_cache_service_id' => $schedule->target_id,
            'backup_configuration_id' => $schedule->backup_configuration_id,
            'status' => RedisSnapshot::STATUS_PENDING,
            'storage_kind' => RedisSnapshot::STORAGE_DESTINATION,
        ]);

        ExportRedisSnapshotJob::dispatch($snapshot->id);
        $this->info('Dispatched cache snapshot '.$snapshot->id);
    }

    private function dispatchDatabaseBackup(BackupSchedule $schedule): void
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

    private function dispatchSiteFilesBackup(BackupSchedule $schedule): void
    {
        $backup = SiteFileBackup::create([
            'site_id' => $schedule->target_id,
            'user_id' => null,
            'status' => SiteFileBackup::STATUS_PENDING,
            'backup_configuration_id' => $schedule->backup_configuration_id,
        ]);

        ExportSiteFileBackupJob::dispatch($backup->id);
        $this->info('Dispatched site files backup '.$backup->id);
    }
}
