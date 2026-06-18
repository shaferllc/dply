<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ExportRedisSnapshotJob;
use App\Livewire\Servers\WorkspaceSnapshots;
use App\Models\BackupConfiguration;
use App\Models\RedisSnapshot;
use App\Models\RedisSnapshotSchedule;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCronJob;
use Illuminate\Support\Collection;

/**
 * Cache (Redis-family) RDB snapshot behaviour for the Snapshots workspace hub.
 *
 * Extracted verbatim from the former WorkspaceRedisSnapshots component so the
 * hub's "Cache" tab keeps full parity: run-now triggers, recurring schedule CRUD,
 * and the history list, all backed by {@see RedisSnapshot} / {@see RedisSnapshotSchedule}
 * and the {@see ExportRedisSnapshotJob} pipeline.
 *
 * Method names are prefixed `redis*` so they don't collide with the site-database
 * and server-image actions that live alongside them on {@see WorkspaceSnapshots}.
 *
 * @property Server $server Set in mount() by InteractsWithServerWorkspace.
 */
trait ManagesRedisSnapshots
{
    /** Form: existing BackupConfiguration to use for the run-now snapshot. */
    public string $run_now_destination_id = '';

    /** Form: cron expression for a new schedule (defaults to nightly at 03:00). */
    public string $new_cron_expression = '0 3 * * *';

    /** Form: destination configuration for a new schedule. */
    public string $new_destination_id = '';

    /** Form: which cache-service row a new schedule targets. */
    public string $new_cache_service_id = '';

    public function runRedisSnapshotNow(): void
    {
        $this->authorize('update', $this->server);

        $row = $this->primaryCacheService();
        if ($row === null) {
            $this->toastError(__('No redis-family cache service installed on this server.'));

            return;
        }

        $configuration = $this->run_now_destination_id !== ''
            ? BackupConfiguration::query()
                ->where('organization_id', $this->server->organization_id)
                ->whereKey($this->run_now_destination_id)
                ->first()
            : null;

        if ($configuration === null) {
            $this->toastError(__('Pick an S3-style destination configured on your org.'));

            return;
        }

        $snapshot = RedisSnapshot::query()->create([
            'server_id' => $this->server->id,
            'server_cache_service_id' => $row->id,
            'user_id' => auth()->id(),
            'backup_configuration_id' => $configuration->id,
            'status' => RedisSnapshot::STATUS_PENDING,
            'storage_kind' => RedisSnapshot::STORAGE_DESTINATION,
        ]);

        ExportRedisSnapshotJob::dispatch($snapshot->id);

        $this->dispatchSnapshotNotification('created', [__('Cache snapshot (:engine)', ['engine' => $row->engine])], [
            'snapshot_type' => 'cache',
            'redis_snapshot_id' => $snapshot->id,
            'engine' => $row->engine,
        ]);

        $this->toastSuccess(__('Snapshot queued. Check History for completion.'));
    }

    public function addRedisSchedule(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'new_cron_expression' => 'required|string|max:64',
            'new_destination_id' => 'required|string',
            'new_cache_service_id' => 'required|string',
        ]);

        $row = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->new_cache_service_id)
            ->first();
        if ($row === null) {
            $this->toastError(__('Cache service not found on this server.'));

            return;
        }

        if (RedisSnapshotSchedule::query()->where('server_cache_service_id', $row->id)->exists()) {
            $this->toastError(__('A schedule already exists for this cache service. Delete it first to change cadence.'));

            return;
        }

        $configuration = BackupConfiguration::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereKey($this->new_destination_id)
            ->first();
        if ($configuration === null) {
            $this->toastError(__('Destination not found.'));

            return;
        }

        $schedule = RedisSnapshotSchedule::create([
            'server_id' => $this->server->id,
            'server_cache_service_id' => $row->id,
            'backup_configuration_id' => $configuration->id,
            'cron_expression' => $this->new_cron_expression,
            'is_active' => true,
        ]);

        // Control-plane cron entry — fires on this dply install's cron, not the
        // remote box. user/host are nominal because nothing SSHes for us here.
        $cronJob = ServerCronJob::create([
            'server_id' => $this->server->id,
            'cron_expression' => $this->new_cron_expression,
            'command' => 'php '.base_path('artisan').' dply:run-redis-snapshot-schedule '.$schedule->id,
            'user' => 'root',
            'enabled' => true,
            'description' => 'Redis snapshot schedule '.$schedule->id,
            'system_managed' => true,
        ]);

        $schedule->update(['server_cron_job_id' => $cronJob->id]);

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'redis_snapshot.schedule.created', $schedule, null, [
                'cache_service_id' => $row->id,
                'engine' => $row->engine,
                'cron_expression' => $schedule->cron_expression,
            ]);
        }

        $this->reset(['new_destination_id', 'new_cache_service_id']);
        $this->new_cron_expression = '0 3 * * *';
        $this->toastSuccess(__('Snapshot schedule added.'));
    }

    public function toggleRedisSchedule(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = RedisSnapshotSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $next = ! $schedule->is_active;
        $schedule->update(['is_active' => $next]);
        if ($schedule->server_cron_job_id) {
            ServerCronJob::query()->whereKey($schedule->server_cron_job_id)->update(['enabled' => $next]);
        }
        $this->toastSuccess($next ? __('Schedule resumed.') : __('Schedule paused.'));
    }

    public function deleteRedisSchedule(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = RedisSnapshotSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        if ($schedule->server_cron_job_id) {
            ServerCronJob::query()->whereKey($schedule->server_cron_job_id)->delete();
        }
        $schedule->delete();
        $this->toastSuccess(__('Schedule deleted.'));
    }

    public function deleteRedisSnapshot(string $snapshotId): void
    {
        $this->authorize('update', $this->server);

        $snapshot = RedisSnapshot::query()
            ->where('server_id', $this->server->id)
            ->whereKey($snapshotId)
            ->first();
        if ($snapshot !== null) {
            $snapshot->delete();
            $this->dispatchSnapshotNotification('deleted', [__('Cache snapshot')], [
                'snapshot_type' => 'cache',
                'redis_snapshot_id' => $snapshotId,
            ]);
        }
        $this->toastSuccess(__('Snapshot deleted.'));
    }

    protected function primaryCacheService(): ?ServerCacheService
    {
        return ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->whereIn('engine', ['redis', 'valkey', 'keydb', 'dragonfly'])
            ->orderByRaw("CASE status WHEN 'running' THEN 0 WHEN 'stopped' THEN 1 ELSE 2 END")
            ->first();
    }

    /**
     * View data for the Cache tab — mirrors the former WorkspaceRedisSnapshots::render().
     *
     * @return array{cacheServices: Collection, destinations: Collection, schedules: Collection, snapshots: Collection}
     */
    protected function redisSnapshotViewData(): array
    {
        $cacheServices = ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->whereIn('engine', ['redis', 'valkey', 'keydb', 'dragonfly'])
            ->get();

        $destinations = BackupConfiguration::query()
            ->where('organization_id', $this->server->organization_id)
            ->orderBy('name')
            ->get();

        $schedules = RedisSnapshotSchedule::query()
            ->where('server_id', $this->server->id)
            ->with(['cacheService', 'backupConfiguration'])
            ->orderByDesc('created_at')
            ->get();

        $snapshots = RedisSnapshot::query()
            ->where('server_id', $this->server->id)
            ->with(['cacheService', 'backupConfiguration', 'user'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return compact('cacheServices', 'destinations', 'schedules', 'snapshots');
    }
}
