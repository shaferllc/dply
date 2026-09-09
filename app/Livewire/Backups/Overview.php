<?php

namespace App\Livewire\Backups;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\QueuesQuickDownloads;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\ServerImage;
use App\Models\Site;
use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Overview extends Component
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
            return view('livewire.backups.overview', ['featureActive' => false]);
        }

        $servers = $org->servers()->orderBy('name')->get();
        $serverIds = $servers->pluck('id');
        $sevenDaysAgo = now()->subDays(7);

        $backupsBase = ServerDatabaseBackup::whereHas(
            'serverDatabase',
            fn ($q) => $q->whereIn('server_id', $serverIds),
        );

        // This page is the Backups overview, so every headline number spans both
        // engines — database dumps and site file archives. The per-site detail
        // still lives on the Files tab.
        $sites = Site::query()->whereIn('server_id', $serverIds)->get(['id', 'name', 'server_id']);
        $filesBase = SiteFileBackup::whereIn('site_id', $sites->pluck('id'));

        $completed7d = (clone $backupsBase)
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->count()
            + (clone $filesBase)
                ->where('status', SiteFileBackup::STATUS_COMPLETED)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();

        $failed7d = (clone $backupsBase)
            ->where('status', ServerDatabaseBackup::STATUS_FAILED)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->count()
            + (clone $filesBase)
                ->where('status', SiteFileBackup::STATUS_FAILED)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();

        $storageBytes = (clone $backupsBase)
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->sum('bytes')
            + (clone $filesBase)
                ->where('status', SiteFileBackup::STATUS_COMPLETED)
                ->sum('bytes');

        $activeSchedules = BackupSchedule::whereIn('server_id', $serverIds)
            ->where('is_active', true)
            ->count();

        $serversWithSchedule = BackupSchedule::whereIn('server_id', $serverIds)
            ->where('is_active', true)
            ->distinct()
            ->pluck('server_id');

        // The landing hero leads with protection coverage, so we keep the actual
        // unprotected servers around (not just the count) to offer a one-click
        // path into each one's backups workspace.
        $unprotectedServers = $servers->whereNotIn('id', $serversWithSchedule)->values();

        $lastSuccessAt = collect([
            (clone $backupsBase)->where('status', ServerDatabaseBackup::STATUS_COMPLETED)->max('created_at'),
            (clone $filesBase)->where('status', SiteFileBackup::STATUS_COMPLETED)->max('created_at'),
        ])->filter()->map(fn ($at) => Carbon::parse($at))->max();

        // Not for display — the type tabs own the schedule tables. This is the
        // raw material for the gaps band: what is protected, and by what kind.
        $schedules = BackupSchedule::query()
            ->whereIn('server_id', $serverIds)
            ->where('is_active', true)
            ->get(['server_id', 'target_type', 'target_id']);

        $recentRuns = $this->recentRuns(clone $backupsBase, clone $filesBase);

        $destinations = $org->backupConfigurations()->orderBy('name')->get();

        $activity = $this->dailyActivity([clone $backupsBase, clone $filesBase]);

        $archives = (clone $filesBase)
            ->where('status', SiteFileBackup::STATUS_COMPLETED)
            ->with('site.server')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // One row per site, newest archive first — the summary the overview shows
        // in place of the full per-site table on the Files tab.
        $archivedSites = $archives
            ->unique('site_id')
            ->take(4)
            ->map(fn (SiteFileBackup $backup) => [
                'site' => $backup->site,
                'at' => $backup->created_at,
                'bytes' => $backup->bytes,
            ])
            ->values();

        $serverCount = $servers->count();
        $protectedCount = $serverCount - $unprotectedServers->count();

        return view('livewire.backups.overview', [
            'featureActive' => true,
            'organization' => $org,
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
            // The console shows every server as a covered/uncovered chip, so the
            // coverage dial has a roster next to it rather than just a ratio.
            // Uncovered first — those are the ones worth reading.
            'roster' => $servers
                ->map(fn (Server $server) => [
                    'name' => $server->name,
                    'server' => $server,
                    'protected' => $serversWithSchedule->contains($server->id),
                ])
                ->sortBy('protected')
                ->values(),
            'files' => [
                'sites' => $sites->count(),
                'archivedSites' => $archives->unique('site_id')->count(),
                'recent' => $archivedSites,
            ],
            'matrix' => $this->coverageMatrix($servers, $sites, $schedules, $serverIds),
            'activity' => $activity,
            'recentRuns' => $recentRuns,
            'destinations' => $destinations,
        ]);
    }

    /**
     * Coverage as a grid: one row per server, one cell per artifact type.
     *
     * Coverage answers "am I protected at all"; this answers "protected against
     * what, and by what". Each cell carries one of four states, and the fourth
     * one is the point: a Redis cache box has no database to dump and a Custom
     * box has no provider image API, so those cells read `na` rather than
     * counting against the server. A cell that has been captured once by hand
     * but has no schedule reads `manual` — it looks like protection in a run
     * feed and is not, which is exactly the case a flat missing/present split
     * hides. See docs/adr/backups-as-a-product.md, decisions 11 and 12.
     *
     * @param  Collection<int, Server>  $servers
     * @param  Collection<int, Site>  $sites
     * @param  Collection<int, BackupSchedule>  $schedules
     * @param  Collection<int, string>  $serverIds
     * @return list<array{key: string, title: string, subtitle: string, url: string, applicable: int, covered: int, cells: array<string, array{state: string, label: string, note: ?string}>}>
     */
    private function coverageMatrix($servers, $sites, $schedules, $serverIds): array
    {
        $byServer = $schedules->groupBy('server_id');
        $sitesByServer = $sites->groupBy('server_id');

        $databaseCounts = ServerDatabase::query()
            ->whereIn('server_id', $serverIds)
            ->selectRaw('server_id, count(*) as total')
            ->groupBy('server_id')
            ->pluck('total', 'server_id');

        // Last successful capture per server, per type — what turns an empty
        // cell into a "manual, not repeating" one.
        $lastDump = ServerDatabaseBackup::query()
            ->join('server_databases', 'server_databases.id', '=', 'server_database_backups.server_database_id')
            ->whereIn('server_databases.server_id', $serverIds)
            ->where('server_database_backups.status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->groupBy('server_databases.server_id')
            ->selectRaw('server_databases.server_id as server_id, max(server_database_backups.created_at) as last_at')
            ->toBase()
            ->pluck('last_at', 'server_id');

        $lastArchive = SiteFileBackup::query()
            ->join('sites', 'sites.id', '=', 'site_file_backups.site_id')
            ->whereIn('sites.server_id', $serverIds)
            ->where('site_file_backups.status', SiteFileBackup::STATUS_COMPLETED)
            ->groupBy('sites.server_id')
            ->selectRaw('sites.server_id as server_id, max(site_file_backups.created_at) as last_at')
            ->toBase()
            ->pluck('last_at', 'server_id');

        $lastImage = ServerImage::query()
            ->whereIn('server_id', $serverIds)
            ->where('status', ServerImage::STATUS_COMPLETED)
            ->groupBy('server_id')
            ->selectRaw('server_id, max(created_at) as last_at')
            ->toBase()
            ->pluck('last_at', 'server_id');

        $rows = [];

        foreach ($servers as $server) {
            /** @var Collection<int, BackupSchedule> $own */
            $own = $byServer->get($server->id) ?? collect();
            $kinds = $own->pluck('target_type')->unique();

            $siteCount = $sitesByServer->get($server->id)?->count() ?? 0;
            $canImage = $server->provider->supportsImageSnapshots();

            $cells = [
                'database' => $this->coverageCell(
                    applicable: ($databaseCounts[$server->id] ?? 0) > 0,
                    scheduled: $kinds->contains(BackupSchedule::TARGET_DATABASE),
                    lastAt: $lastDump[$server->id] ?? null,
                    note: __('no database on this server'),
                ),
                'files' => $this->coverageCell(
                    applicable: $siteCount > 0,
                    scheduled: $kinds->contains(BackupSchedule::TARGET_SITE_FILES),
                    lastAt: $lastArchive[$server->id] ?? null,
                    note: __('no sites on this server'),
                ),
                'image' => $this->coverageCell(
                    applicable: $canImage,
                    // An image is a one-shot artifact, not a schedule target, so
                    // a completed one counts as covered rather than "manual".
                    scheduled: isset($lastImage[$server->id]),
                    lastAt: $lastImage[$server->id] ?? null,
                    note: __('images n/a on :provider', ['provider' => Str::title($server->provider->value)]),
                ),
            ];

            $applicable = collect($cells)->reject(fn (array $cell) => $cell['state'] === 'na');

            $rows[] = [
                'key' => $server->id,
                'title' => $server->name,
                'subtitle' => $this->serverMeta($databaseCounts[$server->id] ?? 0, $siteCount),
                'url' => route('servers.backups', $server),
                'applicable' => $applicable->count(),
                'covered' => $applicable->where('state', 'scheduled')->count(),
                'cells' => $cells,
            ];
        }

        // Least-covered first: the rows worth reading are the empty ones. A row
        // with nothing applicable sinks to the bottom — there is no action on it.
        usort($rows, fn (array $a, array $b) => [
            $a['applicable'] === 0 ? 1 : 0, $a['covered'], -$a['applicable'],
        ] <=> [
            $b['applicable'] === 0 ? 1 : 0, $b['covered'], -$b['applicable'],
        ]);

        return $rows;
    }

    /**
     * @return array{state: string, label: string, note: ?string}
     */
    private function coverageCell(bool $applicable, bool $scheduled, mixed $lastAt, string $note): array
    {
        if (! $applicable) {
            return ['state' => 'na', 'label' => __('n/a'), 'note' => $note];
        }

        $at = $lastAt !== null ? Carbon::parse($lastAt) : null;

        return match (true) {
            $scheduled => ['state' => 'scheduled', 'label' => $at?->diffForHumans(short: true) ?? __('scheduled'), 'note' => null],
            $at !== null => ['state' => 'manual', 'label' => __('manual · :when', ['when' => $at->diffForHumans(short: true)]), 'note' => null],
            default => ['state' => 'missing', 'label' => __('missing'), 'note' => null],
        };
    }

    private function serverMeta(int $databases, int $sites): string
    {
        $parts = [];
        if ($databases > 0) {
            $parts[] = trans_choice(':count database|:count databases', $databases, ['count' => $databases]);
        }
        if ($sites > 0) {
            $parts[] = trans_choice(':count site|:count sites', $sites, ['count' => $sites]);
        }

        return $parts === [] ? __('no databases or sites') : implode(' · ', $parts);
    }

    /**
     * The newest runs from both engines, flattened into one shape the overview
     * feed can render without caring which model a row came from.
     *
     * @param  Builder<ServerDatabaseBackup>  $backups
     * @param  Builder<SiteFileBackup>  $files
     * @return list<array{key: string, kind: string, status: string, name: string, context: string, bytes: ?int, destination: string, at: Carbon}>
     */
    private function recentRuns(Builder $backups, Builder $files): array
    {
        $limit = 25;

        $rows = $backups
            ->with(['serverDatabase.server', 'backupConfiguration'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ServerDatabaseBackup $run) => [
                'key' => 'db-'.$run->id,
                'kind' => 'database',
                'status' => $run->status,
                'name' => $run->serverDatabase->name,
                'context' => $run->serverDatabase->server->name,
                'bytes' => (int) $run->bytes,
                'destination' => (string) ($run->backupConfiguration->name ?? __('Server default')),
                'at' => $run->created_at,
            ]);

        $archives = $files
            ->with('site.server')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (SiteFileBackup $run) => [
                'key' => 'files-'.$run->id,
                'kind' => 'files',
                'status' => $run->status,
                'name' => $run->site->name,
                'context' => $run->site->server->name,
                'bytes' => $run->bytes !== null ? (int) $run->bytes : null,
                'destination' => (string) ($run->storage_kind === SiteFileBackup::STORAGE_KIND_REMOTE_SERVER
                    ? __('On the server')
                    : __('Control plane')),
                'at' => $run->created_at,
            ]);

        return $rows
            ->concat($archives)
            ->sortByDesc('at')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Completed/failed run counts per day for the last two weeks, zero-filled so
     * the hero strip always renders 14 cells regardless of how sparse the data is.
     *
     * @param  list<Builder<ServerDatabaseBackup>|Builder<SiteFileBackup>>  $sources
     * @return list<array{date: Carbon, completed: int, failed: int}>
     */
    private function dailyActivity(array $sources): array
    {
        $days = 14;

        $counts = [];
        foreach ($sources as $source) {
            // toBase(): these rows are aggregates, not models — hydrating them
            // into ServerDatabaseBackup/SiteFileBackup would be a lie.
            $rows = $source
                ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
                ->selectRaw('date(created_at) as day, status, count(*) as total')
                ->groupBy('day', 'status')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $day = (string) $row->day;
                $status = (string) $row->status;
                $counts[$day][$status] = ($counts[$day][$status] ?? 0) + (int) $row->total;
            }
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
            default => $this->toastError(__('Unknown backup target type.')),
        };
    }

    private function dispatchDatabase(BackupSchedule $schedule): void
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

    private function dispatchSiteFiles(BackupSchedule $schedule): void
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
