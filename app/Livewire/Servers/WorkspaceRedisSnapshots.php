<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\ExportRedisSnapshotJob;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\BackupConfiguration;
use App\Models\RedisSnapshot;
use App\Models\RedisSnapshotSchedule;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCronJob;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Snapshots surface for dedicated cache servers (server_role redis/valkey). Run-now
 * triggers + recurring schedule CRUD + history list, parallel to
 * {@see WorkspaceBackups} which serves mysql/postgres/site files.
 *
 * Restore is intentionally NOT in v1 — RDB restore is destructive (SHUTDOWN NOSAVE,
 * dump.rdb replace, restart) and warrants a guarded modal/flow we haven't designed.
 * Operators can manually `scp` an RDB from S3 onto the box meanwhile.
 */
#[Layout('layouts.app')]
class WorkspaceRedisSnapshots extends Component
{
    use InteractsWithServerWorkspace;

    /** Form: existing BackupConfiguration to use for the run-now snapshot. */
    public string $run_now_destination_id = '';

    /** Form: cron expression for a new schedule (defaults to nightly at 03:00). */
    public string $new_cron_expression = '0 3 * * *';

    /** Form: destination configuration for a new schedule. */
    public string $new_destination_id = '';

    /** Form: which cache-service row a new schedule targets. */
    public string $new_cache_service_id = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->authorize('view', $this->server);
    }

    public function runNow(): void
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

        $this->toastSuccess(__('Snapshot queued. Check History for completion.'));
    }

    public function addSchedule(): void
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

    public function toggleSchedule(string $scheduleId): void
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

    public function deleteSchedule(string $scheduleId): void
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

    public function deleteSnapshot(string $snapshotId): void
    {
        $this->authorize('update', $this->server);

        $snapshot = RedisSnapshot::query()
            ->where('server_id', $this->server->id)
            ->whereKey($snapshotId)
            ->first();
        $snapshot?->delete();
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

    public function render(): View
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

        return view('livewire.servers.workspace-redis-snapshots', [
            'cacheServices' => $cacheServices,
            'destinations' => $destinations,
            'schedules' => $schedules,
            'snapshots' => $snapshots,
        ]);
    }
}
