<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Models\ServerBackupSchedule;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Services\Servers\ServerRemovalAdvisor;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsServerBackupView
{


    public function render(): View
    {
        if ($this->comingSoonPreview) {
            $contextSite = $this->context_site_id !== null
                ? Site::query()->where('server_id', $this->server->id)->whereKey($this->context_site_id)->first()
                : null;

            return view('livewire.servers.workspace-backups-preview', [
                'contextSite' => $contextSite,
                'siteDedicatedContext' => $this->siteDedicatedContext,
            ]);
        }

        // No $this->server->refresh() here — route binding (and Livewire hydration)
        // already load the row fresh, and saveDatabaseBackupSettings() refreshes after
        // its own write. A blanket refresh on every render just duplicated the
        // `select * from servers` query that route binding already ran.

        // In site-dedicated context every picker (run-now + new-schedule target)
        // is scoped to the focused site: $sites is just that site, and $databases
        // only the databases linked to it (server_databases.site_id). The server
        // workspace still lists the whole fleet. The runs / schedules / stats
        // collections below narrow the same way.
        $databases = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->when($this->context_site_id !== null, fn ($q) => $q->where('site_id', $this->context_site_id))
            ->orderBy('name')
            ->get();

        // Databases hosted on other servers that sites here attach to. Listed in
        // the run-now picker; their backup runs on their own home server.
        $remoteDatabases = $this->remoteAttachedDatabases();

        // The run button gates on the server that will ACTUALLY run the dump —
        // the selected database's home server. A remote-attached DB dumps on its
        // own box, so this server being un-ready must not block it (and vice
        // versa). Empty selection → not ready, so the button stays disabled.
        $runDatabaseReady = false;
        if ($this->run_database_id !== '') {
            if ($databases->contains('id', $this->run_database_id)) {
                $runDatabaseReady = $this->serverOpsReady();
            } else {
                $remoteSelected = $remoteDatabases->firstWhere('id', $this->run_database_id);
                $runDatabaseReady = $remoteSelected?->server !== null
                    && $this->serverOpsReady($remoteSelected->server);
            }
        }

        $sites = Site::query()
            ->where('server_id', $this->server->id)
            ->when($this->context_site_id !== null, fn ($q) => $q->whereKey($this->context_site_id))
            ->orderBy('name')
            ->get();

        // $databases / $sites are already scoped to the focused site in site
        // context, so the id lists follow directly: DB history shows the site's
        // linked databases (if any), file history shows just this site.
        $databaseIds = $databases->pluck('id')->merge($remoteDatabases->pluck('id'))->unique()->all();
        $siteIds = $sites->pluck('id')->all();

        $databaseBackups = ServerDatabaseBackup::query()
            ->whereIn('server_database_id', $databaseIds)
            ->with('serverDatabase')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $fileBackups = SiteFileBackup::query()
            ->whereIn('site_id', $siteIds)
            ->with('site')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Schedules narrow to those targeting this site (site_files target_type with matching
        // target_id). Database schedules don't surface in site-context because databases
        // belong to the server, not to any one site.
        $schedules = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->when($this->context_site_id !== null, function ($q): void {
                $q->where('target_type', ServerBackupSchedule::TARGET_SITE_FILES)
                    ->where('target_id', $this->context_site_id);
            })
            ->orderBy('created_at')
            ->get();

        // Per-schedule "next run" + most recent status, computed once and passed by id.
        // Next-run parsing uses the same dragonmantank/cron-expression library Laravel
        // ships with; an unparseable expression silently degrades to null (the schedule
        // form rejects garbage at save time, so this is just defensive).
        $scheduleMeta = [];
        foreach ($schedules as $schedule) {
            $next = null;
            try {
                if ($schedule->is_active) {
                    $next = (new CronExpression($schedule->cron_expression))->getNextRunDate('now');
                }
            } catch (\Throwable) {
                $next = null;
            }

            $latestStatus = match ($schedule->target_type) {
                'database' => ServerDatabaseBackup::query()
                    ->where('server_database_id', $schedule->target_id)
                    ->orderByDesc('created_at')
                    ->value('status'),
                'site_files' => SiteFileBackup::query()
                    ->where('site_id', $schedule->target_id)
                    ->orderByDesc('created_at')
                    ->value('status'),
                default => null,
            };

            $recentRuns = match ($schedule->target_type) {
                'database' => ServerDatabaseBackup::query()
                    ->where('server_database_id', $schedule->target_id)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(['id', 'status', 'bytes', 'created_at', 'error_message']),
                'site_files' => SiteFileBackup::query()
                    ->where('site_id', $schedule->target_id)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(['id', 'status', 'bytes', 'created_at', 'error_message']),
                default => collect(),
            };

            $scheduleMeta[$schedule->id] = [
                'next_run_at' => $next,
                'latest_status' => $latestStatus,
                'recent_runs' => $recentRuns,
            ];
        }

        // Destinations are org-scoped — every server in the organization shares
        // the same set, so any teammate's bucket / rclone remote is immediately
        // pickable here without re-entering credentials.
        $backupConfigurations = $this->server->organization
            ? $this->server->organization->backupConfigurations()->orderBy('name')->get()
            : collect();

        // 7-day at-a-glance counts to help operators spot drift without scrolling. Pulled
        // separately from the recent-runs lists (which are capped at 20) so the metrics are
        // accurate even when there's a heavy backup cadence.
        $weekAgo = now()->subDays(7);
        $stats = [
            'db_completed_7d' => ServerDatabaseBackup::query()
                ->whereIn('server_database_id', $databaseIds)
                ->where('status', 'completed')
                ->where('created_at', '>=', $weekAgo)
                ->count(),
            'db_failed_7d' => ServerDatabaseBackup::query()
                ->whereIn('server_database_id', $databaseIds)
                ->where('status', 'failed')
                ->where('created_at', '>=', $weekAgo)
                ->count(),
            'files_completed_7d' => SiteFileBackup::query()
                ->whereIn('site_id', $siteIds)
                ->where('status', 'completed')
                ->where('created_at', '>=', $weekAgo)
                ->count(),
            'files_failed_7d' => SiteFileBackup::query()
                ->whereIn('site_id', $siteIds)
                ->where('status', 'failed')
                ->where('created_at', '>=', $weekAgo)
                ->count(),
            'total_bytes' => (int) ServerDatabaseBackup::query()
                ->whereIn('server_database_id', $databaseIds)
                ->where('status', 'completed')
                ->sum('bytes')
                + (int) SiteFileBackup::query()
                    ->whereIn('site_id', $siteIds)
                    ->where('status', 'completed')
                    ->sum('bytes'),
        ];

        $contextSite = $this->context_site_id !== null
            ? $sites->firstWhere('id', $this->context_site_id)
            : null;

        // Banner + "Running…" button state derive from the DB so they rehydrate
        // for free across reload and show to any operator viewing this server.
        $backupConsoleRun = $this->latestConsoleActionFor($this->server, 'backup_');

        $inFlightKinds = ConsoleAction::query()
            ->forSubject($this->server)
            ->whereIn('kind', ['backup_database', 'backup_site_files'])
            ->notDismissed()
            ->inFlight()
            ->pluck('kind')
            ->all();

        return view('livewire.servers.workspace-backups', [
            'backupConsoleRun' => $backupConsoleRun,
            'dbBackupRunning' => in_array('backup_database', $inFlightKinds, true),
            'filesBackupRunning' => in_array('backup_site_files', $inFlightKinds, true),
            'opsReady' => $this->serverOpsReady(),
            'contextSite' => $contextSite,
            'siteDedicatedContext' => $this->siteDedicatedContext,
            'databases' => $databases,
            'remoteDatabases' => $remoteDatabases,
            'runDatabaseReady' => $runDatabaseReady,
            'sites' => $sites,
            'databaseBackups' => $databaseBackups,
            'fileBackups' => $fileBackups,
            'schedules' => $schedules,
            'scheduleMeta' => $scheduleMeta,
            'backupConfigurations' => $backupConfigurations,
            'stats' => $stats,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'notifChannels' => $this->backups_workspace_tab === 'notifications' ? $this->assignableBackupNotificationChannels() : collect(),
            'notifSubscriptions' => $this->backups_workspace_tab === 'notifications' ? $this->backupNotificationSubscriptions() : collect(),
            'notifEventLabels' => $this->backups_workspace_tab === 'notifications' ? $this->backupEventLabels() : [],
        ]);
    }
}
