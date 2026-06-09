<?php

namespace App\Livewire\Backups;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\BackupConfiguration;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Services\Servers\DatabaseBackupExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Databases extends Component
{
    use DispatchesToastNotifications;

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        if (! Feature::for($org)->active('workspace.backups')) {
            return view('livewire.backups.databases', ['featureActive' => false]);
        }

        $serverIds = $org->servers()->pluck('id');
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

        $unprotectedServers = $serverIds->diff($serversWithSchedule)->count();

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

        return view('livewire.backups.databases', [
            'featureActive' => true,
            'organization'  => $org,
            'metrics'       => [
                'completed7d'       => $completed7d,
                'failed7d'          => $failed7d,
                'storage'           => Number::fileSize((int) $storageBytes),
                'activeSchedules'   => $activeSchedules,
                'unprotectedServers'=> $unprotectedServers,
            ],
            'schedules'     => $schedules,
            'recentRuns'    => $recentRuns,
            'destinations'  => $destinations,
        ]);
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
            ServerBackupSchedule::TARGET_DATABASE   => $this->dispatchDatabase($schedule),
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
            'user_id'            => auth()->id(),
            'status'             => ServerDatabaseBackup::STATUS_PENDING,
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
            'status'  => SiteFileBackup::STATUS_PENDING,
        ]);

        ExportSiteFileBackupJob::dispatch($backup->id);
        $this->toastSuccess(__('Backup queued for :name.', ['name' => $site->name]));
    }
}
