<?php

declare(strict_types=1);

namespace App\Livewire\Backups\Concerns;

use App\Jobs\ExportRedisSnapshotJob;
use App\Models\BackupSchedule;
use App\Models\RedisSnapshot;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use Illuminate\Support\Facades\Gate;

/**
 * Pause/resume and run-now for a {@see BackupSchedule}, shared by the Backups
 * type tabs (Databases, Files, and Snapshots in M2) so each one does not grow
 * its own copy — the mistake that produced two schedule tables in the first
 * place (docs/adr/backups-as-a-product.md, decision 8).
 *
 * "Run now" deliberately does NOT advance `last_run_at`: it is an extra capture
 * an operator asked for, not the scheduled one, and moving the marker would
 * silently skip the next automatic run.
 */
trait RunsBackupSchedules
{
    public function toggleSchedule(string $scheduleId): void
    {
        $schedule = BackupSchedule::with('server')->findOrFail($scheduleId);
        Gate::authorize('update', $schedule->server);

        $newActive = ! $schedule->is_active;
        $schedule->update(['is_active' => $newActive]);

        $this->toastSuccess($newActive ? __('Schedule resumed.') : __('Schedule paused.'));
    }

    public function runScheduleNow(string $scheduleId): void
    {
        $schedule = BackupSchedule::with('server')->findOrFail($scheduleId);
        Gate::authorize('update', $schedule->server);

        match ($schedule->target_type) {
            BackupSchedule::TARGET_DATABASE => $this->dispatchDatabase($schedule),
            BackupSchedule::TARGET_SITE_FILES => $this->dispatchSiteFiles($schedule),
            BackupSchedule::TARGET_CACHE => $this->dispatchCache($schedule),
            default => $this->toastError(__('Unknown backup target type.')),
        };
    }

    private function dispatchDatabase(BackupSchedule $schedule): void
    {
        // Resolve by target alone, exactly as RunBackupScheduleCommand and
        // ExportServerDatabaseBackupJob do. Scoping this to $schedule->server_id
        // made "Run now" fail on a schedule the scheduler runs happily: the dump
        // executes on the DATABASE's server, so a schedule whose server_id has
        // drifted still works — it just could not be triggered by hand.
        $database = ServerDatabase::with('server')->find($schedule->target_id);

        if (! $database) {
            $this->toastError(__('Schedule target database is missing.'));

            return;
        }

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        // The database's own server, not the schedule's — settings resolution
        // (including the default destination) must come from the box the dump
        // actually runs on.
        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $database->server ?? $schedule->server,
            $schedule->backup_configuration_id,
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id);
        $this->toastSuccess(__('Backup queued for :name.', ['name' => $database->name]));
    }

    private function dispatchSiteFiles(BackupSchedule $schedule): void
    {
        // Same reasoning as dispatchDatabase(): the archive runs on the site's
        // own server, so the target is the source of truth, not schedule.server_id.
        $site = Site::with('server')->find($schedule->target_id);

        if (! $site) {
            $this->toastError(__('Schedule target site is missing.'));

            return;
        }

        $backup = SiteFileBackup::create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);

        ExportSiteFileBackupJob::dispatch($backup->id);
        $this->toastSuccess(__('Backup queued for :name.', ['name' => $site->name]));
    }

    private function dispatchCache(BackupSchedule $schedule): void
    {
        $snapshot = RedisSnapshot::query()->create([
            'server_id' => $schedule->server_id,
            'server_cache_service_id' => $schedule->target_id,
            'backup_configuration_id' => $schedule->backup_configuration_id,
            'status' => RedisSnapshot::STATUS_PENDING,
            'storage_kind' => RedisSnapshot::STORAGE_DESTINATION,
        ]);

        ExportRedisSnapshotJob::dispatch($snapshot->id);
        $this->toastSuccess(__('Cache snapshot queued.'));
    }
}
