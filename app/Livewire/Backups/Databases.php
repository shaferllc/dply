<?php

declare(strict_types=1);

namespace App\Livewire\Backups;

use App\Livewire\Backups\Concerns\EditsBackupSchedules;
use App\Livewire\Backups\Concerns\ManagesBackupRunActions;
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
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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
    use EditsBackupSchedules;
    use ManagesBackupRunActions;
    use QueuesQuickDownloads;
    use RunsBackupSchedules;
    use SummarisesBackupRuns;
    use WithPagination;

    /** Free-text over database, server and destination names, plus error text. */
    #[Url(as: 'q', except: '')]
    public string $runSearch = '';

    /** '' = every status. */
    #[Url(as: 'status', except: '')]
    public string $runStatus = '';

    /** Narrow to one destination, or '' for all (including on-server dumps). */
    #[Url(as: 'dest', except: '')]
    public string $runDestination = '';

    public function updatedRunSearch(): void
    {
        // Any filter change invalidates the current page — otherwise a search
        // that returns three rows can land you on page 4 looking at nothing.
        $this->resetPage();
    }

    public function updatedRunStatus(): void
    {
        $this->resetPage();
    }

    public function updatedRunDestination(): void
    {
        $this->resetPage();
    }

    public function clearRunFilters(): void
    {
        $this->runSearch = '';
        $this->runStatus = '';
        $this->runDestination = '';
        $this->resetPage();
    }

    public function hasRunFilters(): bool
    {
        return $this->runSearch !== '' || $this->runStatus !== '' || $this->runDestination !== '';
    }

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

        $runsQuery = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', $ownedDatabases)
            ->with(['serverDatabase.server', 'backupConfiguration'])
            ->when($this->runStatus !== '', fn ($q) => $q->where('status', $this->runStatus))
            ->when($this->runDestination === 'none', fn ($q) => $q->whereNull('backup_configuration_id'))
            ->when($this->runDestination !== '' && $this->runDestination !== 'none',
                fn ($q) => $q->where('backup_configuration_id', $this->runDestination))
            ->when($this->runSearch !== '', function ($q) {
                $term = '%'.str_replace('%', '\\%', trim($this->runSearch)).'%';

                // Search what an operator actually remembers: which database,
                // which box, which bucket, or the error they saw.
                $q->where(function ($inner) use ($term) {
                    $inner->whereHas('serverDatabase', fn ($d) => $d->where('name', 'like', $term))
                        ->orWhereHas('serverDatabase.server', fn ($sv) => $sv->where('name', 'like', $term))
                        ->orWhereHas('backupConfiguration', fn ($c) => $c->where('name', 'like', $term))
                        ->orWhere('error_message', 'like', $term);
                });
            })
            ->orderByDesc('created_at');

        $runs = $runsQuery->paginate(20, ['*'], 'runs');

        // The newest run per database, so a row can show that its last attempt
        // failed. CLAUDE.md forbids SSH in the render path, so health has to be
        // inferred from what already happened rather than probed live — which
        // is also more honest: it reports what the backup engine actually saw.
        $lastRuns = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', $ownedDatabases)
            ->orderByDesc('created_at')
            ->limit(300)
            ->get(['server_database_id', 'status', 'error_message', 'created_at'])
            ->unique('server_database_id')
            ->keyBy('server_database_id');

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
            'lastRuns' => $lastRuns,
            'coverageChecks' => $this->coverage($databases, $schedulesByTarget, $lastRuns),
            'runDestinations' => $org->backupConfigurations()->orderBy('name')->get(['id', 'name']),
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

    /**
     * Coverage as a grid: one row per database, one cell per property that has
     * to hold before a dump is worth anything.
     *
     * The four columns are deliberately not "does a schedule exist" four times.
     * A dump that runs nightly and lands next to the database it came from dies
     * with the box; a schedule whose every run fails looks healthy in a list of
     * schedules. Those are the two ways this page could previously report
     * protection that does not exist. Engines dply cannot dump read `na` rather
     * than counting as a gap — same rule as the Overview grid.
     *
     * @param  Collection<int, ServerDatabase>  $databases
     * @param  Collection<string, Collection<int, BackupSchedule>>  $schedulesByTarget
     * @param  Collection<string, ServerDatabaseBackup>  $lastRuns
     * @return list<array{key: string, title: string, subtitle: string, url: ?string, applicable: int, covered: int, cells: array<string, array{state: string, label: string, note: ?string}>}>
     */
    private function coverage($databases, $schedulesByTarget, $lastRuns): array
    {
        $databaseIds = $databases->pluck('id');

        // Where the newest *successful* dump actually landed. A completed run
        // with no backup configuration stayed on the server it came from.
        $lastCompleted = ServerDatabaseBackup::query()
            ->whereIn('server_database_id', $databaseIds)
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->get(['server_database_id', 'backup_configuration_id', 'bytes', 'created_at'])
            ->unique('server_database_id')
            ->keyBy('server_database_id');

        $rows = [];

        foreach ($databases as $database) {
            $schedules = $schedulesByTarget->get($database->id) ?? collect();
            $active = $schedules->firstWhere('is_active', true);
            $completed = $lastCompleted->get($database->id);
            $lastRun = $lastRuns->get($database->id);
            $canDump = $database->supportsDump();

            $cells = [
                'schedule' => match (true) {
                    $active !== null => ['state' => 'scheduled', 'label' => $active->cronDescription() ?: $active->cron_expression, 'note' => null],
                    $schedules->isNotEmpty() => ['state' => 'manual', 'label' => __('paused'), 'note' => null],
                    ! $canDump => ['state' => 'na', 'label' => __('n/a'), 'note' => __('dply cannot dump :engine', ['engine' => $database->engine])],
                    default => ['state' => 'missing', 'label' => __('none'), 'note' => null],
                },
                'dump' => match (true) {
                    $lastRun?->status === ServerDatabaseBackup::STATUS_FAILED => ['state' => 'failed', 'label' => __('failed :when', ['when' => $lastRun->created_at->diffForHumans(short: true)]), 'note' => $lastRun->error_message],
                    $completed !== null => ['state' => 'scheduled', 'label' => $completed->created_at->diffForHumans(short: true), 'note' => null],
                    ! $canDump => ['state' => 'na', 'label' => __('n/a'), 'note' => null],
                    default => ['state' => 'missing', 'label' => __('never'), 'note' => null],
                },
                'offsite' => match (true) {
                    $completed === null && ! $canDump => ['state' => 'na', 'label' => __('n/a'), 'note' => null],
                    $completed === null => ['state' => 'missing', 'label' => __('nothing stored'), 'note' => null],
                    $completed->backup_configuration_id !== null => ['state' => 'scheduled', 'label' => __('shipped off-box'), 'note' => null],
                    default => ['state' => 'missing', 'label' => __('on the server'), 'note' => __('This copy dies with the machine it came from.')],
                },
                'restore' => $canDump
                    ? ['state' => 'scheduled', 'label' => __('available'), 'note' => null]
                    : ['state' => 'na', 'label' => __('n/a'), 'note' => __('no dump path for :engine', ['engine' => $database->engine])],
            ];

            $applicable = collect($cells)->reject(fn (array $cell) => $cell['state'] === 'na');

            $rows[] = [
                'key' => $database->id,
                'title' => $database->name,
                'subtitle' => ($database->server?->name ?? '—').' · '.$database->engine,
                'url' => $database->server ? route('servers.backups', $database->server) : null,
                'applicable' => $applicable->count(),
                'covered' => $applicable->where('state', 'scheduled')->count(),
                'cells' => $cells,
            ];
        }

        // Least-covered first: the rows worth reading are the empty ones. A row
        // with nothing applicable at all sinks to the bottom instead of leading
        // on a zero — there is no action to take on it.
        usort($rows, fn (array $a, array $b) => [
            $a['applicable'] === 0 ? 1 : 0, $a['covered'], -$a['applicable'],
        ] <=> [
            $b['applicable'] === 0 ? 1 : 0, $b['covered'], -$b['applicable'],
        ]);

        return $rows;
    }
}
