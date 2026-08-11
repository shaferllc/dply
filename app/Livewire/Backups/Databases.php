<?php

declare(strict_types=1);

namespace App\Livewire\Backups;

use App\Livewire\Backups\Concerns\RunsBackupSchedules;
use App\Livewire\Backups\Concerns\SummarisesBackupRuns;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\QueuesQuickDownloads;
use App\Models\BackupSchedule;
use App\Models\Organization;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The Databases tab: every database dply can dump, the schedules protecting
 * them, their run history, and a one-click dump to the browser.
 *
 * Owns its type end-to-end — the Overview deliberately does not repeat the
 * schedules table (docs/adr/backups-as-a-product.md, decision 1).
 */
#[Layout('layouts.app')]
class Databases extends Component
{
    use DispatchesToastNotifications;
    use QueuesQuickDownloads;
    use RunsBackupSchedules;
    use SummarisesBackupRuns;

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org instanceof Organization) {
            abort(403, 'Select an organization first.');
        }

        if (! Feature::for($org)->active('workspace.backups')) {
            return view('livewire.backups.databases', ['featureActive' => false]);
        }

        $serverIds = $org->servers()->pluck('id');
        $ownedDatabases = fn ($q) => $q->whereIn('server_id', $serverIds);

        $databases = ServerDatabase::query()
            ->whereIn('server_id', $serverIds)
            ->with('server')
            ->orderBy('name')
            ->get();

        $schedules = BackupSchedule::query()
            ->where('target_type', BackupSchedule::TARGET_DATABASE)
            ->whereIn('server_id', $serverIds)
            ->with(['server', 'backupConfiguration'])
            ->orderByDesc('is_active')
            ->orderByDesc('last_run_at')
            ->get();

        // Which databases already have a schedule — drives the per-row
        // "Protected / Not scheduled" state and the tab's coverage line.
        $scheduledTargetIds = $schedules->where('is_active', true)->pluck('target_id')->all();

        $runs = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', fn ($q) => $q->whereIn('server_id', $serverIds))
            ->with(['serverDatabase.server', 'backupConfiguration'])
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $storageBytes = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', fn ($q) => $q->whereIn('server_id', $serverIds))
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->sum('bytes');

        // The view renders one row per database with its schedule folded in, so
        // the schedules that no longer point at a live database have to be
        // surfaced separately or they would silently vanish from the tab.
        $schedulesByTarget = $schedules->groupBy('target_id');
        $databaseIds = $databases->pluck('id')->all();
        $orphanSchedules = $schedules
            ->reject(fn (BackupSchedule $schedule) => in_array($schedule->target_id, $databaseIds, true))
            ->values();

        return view('livewire.backups.databases', [
            'featureActive' => true,
            'organization' => $org,
            'databases' => $databases,
            'schedules' => $schedules,
            'schedulesByTarget' => $schedulesByTarget,
            'orphanSchedules' => $orphanSchedules,
            'scheduledTargetIds' => $scheduledTargetIds,
            'nextRuns' => $this->nextRuns($schedules),
            'trends' => $this->recentSizes(
                ServerDatabaseBackup::query()->whereHas('serverDatabase', $ownedDatabases),
                'server_database_id',
            ),
            'activity' => $this->dailyActivity(
                ServerDatabaseBackup::query()->whereHas('serverDatabase', $ownedDatabases),
            ),
            'engineMix' => $databases
                ->groupBy(fn (ServerDatabase $database) => $database->engine)
                ->map->count()
                ->sortDesc(),
            'runs' => $runs,
            'metrics' => [
                'databases' => $databases->count(),
                'protected' => count(array_unique($scheduledTargetIds)),
                'storage' => Number::fileSize((int) $storageBytes),
                'coverage' => $databases->count() > 0
                    ? (int) round(count(array_unique($scheduledTargetIds)) / $databases->count() * 100)
                    : 0,
            ],
        ]);
    }

}
