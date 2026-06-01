<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExportRedisSnapshotJob;
use App\Models\RedisSnapshot;
use App\Models\RedisSnapshotSchedule;
use App\Models\ServerCronJob;
use Illuminate\Console\Command;

/**
 * Invoked by the control-plane cron entry that materialises a
 * {@see RedisSnapshotSchedule}. Mirrors {@see RunBackupScheduleCommand} —
 * schedule row is the source of truth for cadence, so editing the schedule
 * does not require rewriting the cron line.
 */
class RunRedisSnapshotScheduleCommand extends Command
{
    protected $signature = 'dply:run-redis-snapshot-schedule {schedule}';

    protected $description = 'Create a pending RedisSnapshot row and dispatch the export job for the given RedisSnapshotSchedule.';

    /** Auto-disable a schedule after this many consecutive failed snapshots. */
    private const FAILURE_AUTO_PAUSE_THRESHOLD = 3;

    public function handle(): int
    {
        $schedule = RedisSnapshotSchedule::query()
            ->with(['server', 'cacheService', 'backupConfiguration'])
            ->find((string) $this->argument('schedule'));
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

        $row = $schedule->cacheService;
        $server = $schedule->server;
        if ($row === null || $server === null) {
            $this->error('Schedule has no cache service or server — cannot run.');

            return self::FAILURE;
        }

        $snapshot = RedisSnapshot::query()->create([
            'server_id' => $server->id,
            'server_cache_service_id' => $row->id,
            'backup_configuration_id' => $schedule->backup_configuration_id,
            'status' => RedisSnapshot::STATUS_PENDING,
            'storage_kind' => RedisSnapshot::STORAGE_DESTINATION,
        ]);

        ExportRedisSnapshotJob::dispatch($snapshot->id);

        $schedule->update(['last_run_at' => now()]);

        return self::SUCCESS;
    }

    private function shouldAutoPause(RedisSnapshotSchedule $schedule): bool
    {
        $threshold = self::FAILURE_AUTO_PAUSE_THRESHOLD;
        $recent = RedisSnapshot::query()
            ->where('server_cache_service_id', $schedule->server_cache_service_id)
            ->orderByDesc('created_at')
            ->limit($threshold)
            ->pluck('status')
            ->all();

        return count($recent) >= $threshold
            && array_unique($recent) === [RedisSnapshot::STATUS_FAILED];
    }
}
