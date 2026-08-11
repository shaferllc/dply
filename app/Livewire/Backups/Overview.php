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
            'gaps' => $this->gaps($servers, $sites, $schedules, $serverIds),
            'activity' => $activity,
            'recentRuns' => $recentRuns,
            'destinations' => $destinations,
        ]);
    }

    /**
     * What each server is missing, capability-aware.
     *
     * Coverage answers "am I protected at all"; this answers "protected against
     * what". A server with a nightly dump but no image can lose its whole
     * machine configuration; a server with an image but no dump restores to a
     * crash-consistent database. Rows only ever suggest captures that server can
     * actually do — a Custom box has no provider image API, and saying so is
     * information, while nagging about it would not be. See
     * docs/adr/backups-as-a-product.md, decisions 11 and 12.
     *
     * @param  Collection<int, Server>  $servers
     * @param  Collection<int, Site>  $sites
     * @param  Collection<int, BackupSchedule>  $schedules
     * @param  Collection<int, string>  $serverIds
     * @return list<array{server: Server, missing: list<string>, note: ?string, protected: bool}>
     */
    private function gaps($servers, $sites, $schedules, $serverIds): array
    {
        $byServer = $schedules->groupBy('server_id');
        $sitesByServer = $sites->groupBy('server_id');
        // Only flag a missing dump on a server that actually hosts a database —
        // telling an Edge box it has no SQL backup is noise, not a gap.
        $databaseCounts = ServerDatabase::query()
            ->whereIn('server_id', $serverIds)
            ->selectRaw('server_id, count(*) as total')
            ->groupBy('server_id')
            ->pluck('total', 'server_id');
        $imagedServerIds = ServerImage::query()
            ->whereIn('server_id', $servers->pluck('id'))
            ->where('status', ServerImage::STATUS_COMPLETED)
            ->distinct()
            ->pluck('server_id')
            ->all();

        $gaps = [];

        foreach ($servers as $server) {
            /** @var Collection<int, BackupSchedule> $own */
            $own = $byServer->get($server->id) ?? collect();
            $kinds = $own->pluck('target_type')->unique();

            $missing = [];
            if (($databaseCounts[$server->id] ?? 0) > 0
                && ! $kinds->contains(BackupSchedule::TARGET_DATABASE)) {
                $missing[] = __('database dump');
            }
            // Only a server that actually hosts sites can be missing a file archive.
            if (($sitesByServer->get($server->id)?->count() ?? 0) > 0
                && ! $kinds->contains(BackupSchedule::TARGET_SITE_FILES)) {
                $missing[] = __('file archive');
            }

            $canImage = $server->provider->supportsImageSnapshots();
            if ($canImage && ! in_array($server->id, $imagedServerIds, true)) {
                $missing[] = __('server image');
            }

            if ($missing === []) {
                continue;
            }

            $gaps[] = [
                'server' => $server,
                'missing' => $missing,
                'note' => $canImage ? null : __('images n/a on :provider', [
                    'provider' => Str::title($server->provider->value),
                ]),
                'protected' => $own->isNotEmpty(),
            ];
        }

        return $gaps;
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
