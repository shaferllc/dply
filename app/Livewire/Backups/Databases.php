<?php

namespace App\Livewire\Backups;

use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\QueuesQuickDownloads;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Databases extends Component
{
    use DispatchesToastNotifications;
    use QueuesQuickDownloads;

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        if (! Feature::for($org)->active('workspace.backups')) {
            return view('livewire.backups.databases', ['featureActive' => false]);
        }

        $servers = $org->servers()->orderBy('name')->get();
        $serverIds = $servers->pluck('id');
        $sevenDaysAgo = now()->subDays(7);

        $backupsBase = ServerDatabaseBackup::whereHas(
            'serverDatabase',
            fn ($q) => $q->whereIn('server_id', $serverIds),
        );

        $completed7d = (clone $backupsBase)
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->count();

        $failed7d = (clone $backupsBase)
            ->where('status', ServerDatabaseBackup::STATUS_FAILED)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->count();

        $storageBytes = (clone $backupsBase)
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->sum('bytes');

        $activeSchedules = ServerBackupSchedule::whereIn('server_id', $serverIds)
            ->where('is_active', true)
            ->count();

        $serversWithSchedule = ServerBackupSchedule::whereIn('server_id', $serverIds)
            ->where('is_active', true)
            ->distinct()
            ->pluck('server_id');

        // The landing hero leads with protection coverage, so we keep the actual
        // unprotected servers around (not just the count) to offer a one-click
        // path into each one's backups workspace.
        $unprotectedServers = $servers->whereNotIn('id', $serversWithSchedule)->values();

        $lastSuccessAt = (clone $backupsBase)
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->max('created_at');

        $schedules = ServerBackupSchedule::with(['server', 'backupConfiguration'])
            ->whereIn('server_id', $serverIds)
            ->orderByDesc('is_active')
            ->orderByDesc('last_run_at')
            ->get();

        $recentRuns = (clone $backupsBase)
            ->with(['serverDatabase.server', 'backupConfiguration'])
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $destinations = $org->backupConfigurations()->orderBy('name')->get();

        $activity = $this->dailyActivity(clone $backupsBase);

        $databases = ServerDatabase::query()
            ->whereIn('server_id', $serverIds)
            ->with('server')
            ->orderBy('name')
            ->get();

        $serverCount = $servers->count();
        $protectedCount = $serverCount - $unprotectedServers->count();

        return view('livewire.backups.databases', [
            'featureActive' => true,
            'organization' => $org,
            'databases' => $databases,
            'metrics' => [
                'completed7d' => $completed7d,
                'failed7d' => $failed7d,
                'storage' => Number::fileSize((int) $storageBytes),
                'activeSchedules' => $activeSchedules,
                'unprotectedServers' => $unprotectedServers->count(),
                'servers' => $serverCount,
                'protectedServers' => $protectedCount,
                'coverage' => $serverCount > 0 ? (int) round($protectedCount / $serverCount * 100) : 0,
                'lastSuccessAt' => $lastSuccessAt ? Carbon::parse($lastSuccessAt) : null,
            ],
            'unprotectedServers' => $unprotectedServers,
            'activity' => $activity,
            'schedules' => $schedules,
            'recentRuns' => $recentRuns,
            'destinations' => $destinations,
        ]);
    }

    /**
     * Completed/failed run counts per day for the last two weeks, zero-filled so
     * the hero strip always renders 14 cells regardless of how sparse the data is.
     *
     * @param  Builder<ServerDatabaseBackup>  $backups
     * @return list<array{date: Carbon, completed: int, failed: int}>
     */
    private function dailyActivity(Builder $backups): array
    {
        $days = 14;

        $rows = $backups
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('date(created_at) as day, status, count(*) as total')
            ->groupBy('day', 'status')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->day][(string) $row->status] = (int) $row->total;
        }

        $activity = [];
        for ($ago = $days - 1; $ago >= 0; $ago--) {
            $date = now()->subDays($ago)->startOfDay();
            $key = $date->toDateString();

            $activity[] = [
                'date' => $date,
                'completed' => $counts[$key][ServerDatabaseBackup::STATUS_COMPLETED] ?? 0,
                'failed' => $counts[$key][ServerDatabaseBackup::STATUS_FAILED] ?? 0,
            ];
        }

        return $activity;
    }

    public function toggleSchedule(string $scheduleId): void
    {
        $schedule = ServerBackupSchedule::with('server')->findOrFail($scheduleId);
        Gate::authorize('update', $schedule->server);

        $newActive = ! $schedule->is_active;
        $schedule->update(['is_active' => $newActive]);

        if ($schedule->server_cron_job_id) {
            ServerCronJob::whereKey($schedule->server_cron_job_id)->update(['enabled' => $newActive]);
        }

        $this->toastSuccess($newActive ? __('Schedule resumed.') : __('Schedule paused.'));
    }

    public function runScheduleNow(string $scheduleId): void
    {
        $schedule = ServerBackupSchedule::with('server')->findOrFail($scheduleId);
        Gate::authorize('update', $schedule->server);

        match ($schedule->target_type) {
            ServerBackupSchedule::TARGET_DATABASE => $this->dispatchDatabase($schedule),
            ServerBackupSchedule::TARGET_SITE_FILES => $this->dispatchSiteFiles($schedule),
            default => $this->toastError(__('Unknown backup target type.')),
        };
    }

    private function dispatchDatabase(ServerBackupSchedule $schedule): void
    {
        $database = ServerDatabase::whereKey($schedule->target_id)
            ->where('server_id', $schedule->server_id)
            ->first();

        if (! $database) {
            $this->toastError(__('Schedule target database is missing.'));

            return;
        }

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $schedule->server,
            $schedule->backup_configuration_id,
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id);
        $this->toastSuccess(__('Backup queued for :name.', ['name' => $database->name]));
    }

    private function dispatchSiteFiles(ServerBackupSchedule $schedule): void
    {
        $site = Site::whereKey($schedule->target_id)
            ->where('server_id', $schedule->server_id)
            ->first();

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
}
