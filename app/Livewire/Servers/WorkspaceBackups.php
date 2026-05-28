<?php

namespace App\Livewire\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Concerns\AuthorsBackupDestinations;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\BackupConfiguration;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Notifications\BackupFailureNotification;
use App\Services\Servers\ServerRemovalAdvisor;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-scoped backups workspace: surfaces existing {@see ServerDatabaseBackup}
 * and {@see SiteFileBackup} runs for everything attached to the server, plus
 * recurring schedule CRUD via {@see ServerBackupSchedule}.
 *
 * Schedules materialize as {@see ServerCronJob} entries that invoke
 * `dply:run-backup-schedule {schedule}` so the cron line stays stable across
 * cadence edits.
 */
#[Layout('layouts.app')]
class WorkspaceBackups extends Component
{
    use AuthorsBackupDestinations;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** Form state for "Run database backup now". */
    public string $run_database_id = '';

    /** Form state for "Run site files backup now". */
    public string $run_site_id = '';

    /** New schedule form. */
    public string $new_target_type = ServerBackupSchedule::TARGET_DATABASE;

    public string $new_target_id = '';

    public string $new_cron_expression = '0 3 * * *';

    public ?string $new_backup_configuration_id = null;

    /**
     * "Add backup destination" modal state. Mirrors the settings page form but
     * scoped to the current server's organization, so a teammate adding an
     * S3 bucket here for server A immediately makes it available on every
     * other server in the org too.
     */
    public bool $showDestinationModal = false;

    /** @var array<string, mixed> */
    public array $destinationForm = [];

    /** When set (?site=…), all queries on this page narrow to backups/sites for that site only. */
    public ?string $context_site_id = null;

    /** overview | schedules | history */
    public string $backups_workspace_tab = 'overview';

    public function setBackupsWorkspaceTab(string $tab): void
    {
        $this->backups_workspace_tab = in_array($tab, ['overview', 'schedules', 'history'], true) ? $tab : 'overview';
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->destinationForm = $this->emptyDestinationForm();

        // Site sidebar's "Backups" entry navigates here with ?site={id}; honoring it pre-filters
        // the page so operators don't see noise from other sites on the same server.
        $siteId = request()->query('site');
        if (is_string($siteId) && $siteId !== '') {
            $exists = Site::query()
                ->where('server_id', $server->id)
                ->whereKey($siteId)
                ->exists();
            if ($exists) {
                $this->context_site_id = $siteId;
                $this->new_target_type = ServerBackupSchedule::TARGET_SITE_FILES;
                $this->new_target_id = $siteId;
            }
        }
    }

    /**
     * Opens the inline "Add backup destination" modal. The created destination
     * belongs to the server's organization and is immediately selected on the
     * schedule form, so the operator stays on the same page from "no
     * destinations" to "schedule queued".
     */
    public function openDestinationModal(): void
    {
        $this->authorize('create', BackupConfiguration::class);
        $this->resetErrorBag();
        $this->destinationForm = $this->emptyDestinationForm();
        $this->showDestinationModal = true;
    }

    public function closeDestinationModal(): void
    {
        $this->showDestinationModal = false;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->resetErrorBag();
    }

    public function saveDestination(): void
    {
        $this->authorize('create', BackupConfiguration::class);

        $org = $this->server->organization;
        if ($org === null) {
            $this->toastError(__('This server has no organization — refresh the page.'));

            return;
        }

        $this->resetErrorBag();
        $this->validate($this->destinationFormRules('destinationForm', $this->destinationForm['provider'] ?? ''));
        $this->validateDestinationFormExtras('destinationForm', $this->destinationForm);

        $row = $org->backupConfigurations()->create([
            'name' => $this->destinationForm['name'],
            'provider' => $this->destinationForm['provider'],
            'config' => $this->extractDestinationConfig($this->destinationForm),
            'created_by_user_id' => Auth::id(),
        ]);

        // Auto-select the new destination so the operator's next action is to
        // submit the schedule, not to scroll-find the entry they just created.
        $this->new_backup_configuration_id = $row->id;

        $this->showDestinationModal = false;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->toastSuccess(__('Backup destination added — selected on this schedule.'));
    }

    public function runDatabaseBackup(): void
    {
        $this->authorize('update', $this->server);

        $database = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->run_database_id)
            ->first();

        if ($database === null) {
            $this->toastError(__('Pick a database to back up.'));

            return;
        }

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        ExportServerDatabaseBackupJob::dispatch($backup->id);

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.database.run_dispatched', $this->server, null, [
                'backup_id' => (string) $backup->id,
                'database_id' => (string) $database->id,
                'database_name' => $database->name,
            ]);
        }

        $this->run_database_id = '';
        $this->toastSuccess(__('Database backup queued for :name.', ['name' => $database->name]));
    }

    public function runSiteFilesBackup(): void
    {
        $this->authorize('update', $this->server);

        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->run_site_id)
            ->first();

        if ($site === null) {
            $this->toastError(__('Pick a site to back up.'));

            return;
        }

        $backup = SiteFileBackup::create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);

        ExportSiteFileBackupJob::dispatch($backup->id);

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.site_files.run_dispatched', $site, null, [
                'backup_id' => (string) $backup->id,
                'site_id' => (string) $site->id,
                'site_name' => $site->name,
            ]);
        }

        $this->run_site_id = '';
        $this->toastSuccess(__('Site files backup queued for :name.', ['name' => $site->name]));
    }

    public function addSchedule(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'new_target_type' => 'required|in:database,site_files',
            'new_target_id' => 'required|string',
            'new_cron_expression' => 'required|string|max:64',
            'new_backup_configuration_id' => 'nullable|string',
        ]);

        $exists = match ($this->new_target_type) {
            ServerBackupSchedule::TARGET_DATABASE => ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->new_target_id)
                ->exists(),
            ServerBackupSchedule::TARGET_SITE_FILES => Site::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->new_target_id)
                ->exists(),
            default => false,
        };
        if (! $exists) {
            $this->toastError(__('Target not found on this server.'));

            return;
        }

        $schedule = ServerBackupSchedule::create([
            'server_id' => $this->server->id,
            'target_type' => $this->new_target_type,
            'target_id' => $this->new_target_id,
            'backup_configuration_id' => $this->new_backup_configuration_id ?: null,
            'cron_expression' => $this->new_cron_expression,
            'is_active' => true,
        ]);

        // The cron entry runs the dply control-plane artisan command (this dply install),
        // not anything on the remote server — so user defaults to root and host is irrelevant
        // for execution. We just need a stable record so the schedule can be edited/disabled.
        $cronJob = ServerCronJob::create([
            'server_id' => $this->server->id,
            'cron_expression' => $this->new_cron_expression,
            'command' => 'php '.base_path('artisan').' dply:run-backup-schedule '.$schedule->id,
            'user' => 'root',
            'enabled' => true,
            'description' => 'Backup schedule '.$schedule->id,
            'system_managed' => true,
        ]);

        $schedule->update(['server_cron_job_id' => $cronJob->id]);

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.created', $schedule, null, [
                'target_type' => $schedule->target_type,
                'target_id' => $schedule->target_id,
                'cron_expression' => $schedule->cron_expression,
            ]);
        }

        $this->reset(['new_target_id', 'new_backup_configuration_id']);
        $this->new_cron_expression = '0 3 * * *';
        $this->toastSuccess(__('Backup schedule added.'));
    }

    public function downloadDatabaseBackup(string $backupId): StreamedResponse|Response|null
    {
        $this->authorize('update', $this->server);
        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->with('serverDatabase')
            ->firstOrFail();

        if ($backup->status !== ServerDatabaseBackup::STATUS_COMPLETED || empty($backup->disk_path)) {
            $this->toastError(__('Backup is not ready yet.'));

            return null;
        }

        $disk = Storage::disk(config('server_database.backup_disk', 'local'));
        if (! $disk->exists($backup->disk_path)) {
            $this->toastError(__('Backup file is missing from storage.'));

            return null;
        }

        $extension = $backup->serverDatabase?->engine === 'sqlite' ? 'db' : 'sql';
        $filename = ($backup->serverDatabase?->name ?? 'database').'-'.$backup->id.'.'.$extension;

        return $disk->download($backup->disk_path, $filename);
    }

    public function downloadFileBackup(string $backupId): StreamedResponse|Response|null
    {
        $this->authorize('update', $this->server);
        $backup = SiteFileBackup::query()
            ->whereKey($backupId)
            ->whereHas('site', fn ($q) => $q->where('server_id', $this->server->id))
            ->with('site')
            ->firstOrFail();

        if ($backup->status !== SiteFileBackup::STATUS_COMPLETED || empty($backup->disk_path)) {
            $this->toastError(__('Backup is not ready yet.'));

            return null;
        }

        if (! Storage::disk('local')->exists($backup->disk_path)) {
            $this->toastError(__('Backup file is missing from storage.'));

            return null;
        }

        $slug = $backup->site?->slug;
        $name = 'site-files-'.(($slug !== null && $slug !== '') ? $slug : 'site').'-'.$backup->id.'.tar.gz';

        return Storage::disk('local')->download($backup->disk_path, $name);
    }

    public function deleteSchedule(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        if ($schedule->server_cron_job_id) {
            ServerCronJob::query()->whereKey($schedule->server_cron_job_id)->delete();
        }

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.deleted', $schedule, [
                'cron_expression' => $schedule->cron_expression,
            ], null);
        }

        $schedule->delete();
        $this->toastSuccess(__('Backup schedule removed.'));
    }

    /** Inline-edit form state: schedule id → new cron expression. Empty = not editing. */
    public array $editing_schedules = [];

    public function startEditSchedule(string $scheduleId): void
    {
        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }
        $this->editing_schedules[$scheduleId] = $schedule->cron_expression;
    }

    public function cancelEditSchedule(string $scheduleId): void
    {
        unset($this->editing_schedules[$scheduleId]);
    }

    public function saveScheduleCadence(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $newCron = trim((string) ($this->editing_schedules[$scheduleId] ?? ''));
        if ($newCron === '' || strlen($newCron) > 64) {
            $this->toastError(__('Invalid cron expression.'));

            return;
        }

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $oldCron = $schedule->cron_expression;
        $schedule->update(['cron_expression' => $newCron]);
        if ($schedule->server_cron_job_id) {
            ServerCronJob::query()
                ->whereKey($schedule->server_cron_job_id)
                ->update(['cron_expression' => $newCron]);
        }

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                'backup.schedule.cadence_updated',
                $schedule,
                ['cron_expression' => $oldCron],
                ['cron_expression' => $newCron],
            );
        }

        unset($this->editing_schedules[$scheduleId]);
        $this->toastSuccess(__('Schedule updated.'));
    }

    /**
     * Kick off the export job for a schedule's target immediately — same path the
     * cron tick takes. Useful for testing a freshly-added schedule or after fixing
     * destination credentials.
     */
    /**
     * Fire a one-shot {@see BackupFailureNotification} with the
     * test marker so operators can validate their email/recipient setup without
     * inducing an actual backup failure.
     */
    public function sendTestAlert(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $org = $this->server->organization;
        if ($org === null) {
            $this->toastError(__('No organization for this server.'));

            return;
        }

        $admins = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
        if ($admins->isEmpty()) {
            $this->toastError(__('No org admins to send to.'));

            return;
        }

        Notification::send($admins, new BackupFailureNotification(
            schedule: $schedule,
            errorMessage: __('Test alert triggered by :user.', ['user' => auth()->user()?->email ?? 'operator']),
            serverName: (string) ($this->server->name ?? ''),
            isTest: true,
        ));

        audit_log($org, auth()->user(), 'backup.schedule.test_alert', $schedule);

        $this->toastSuccess(__('Test alert sent to :n admin(s).', ['n' => $admins->count()]));
    }

    public function toggleNotifyOnFailure(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $newValue = ! $schedule->notify_on_failure;
        $schedule->update(['notify_on_failure' => $newValue]);

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                $newValue ? 'backup.schedule.notify_enabled' : 'backup.schedule.notify_disabled',
                $schedule,
            );
        }

        $this->toastSuccess($newValue ? __('Failure alerts enabled.') : __('Failure alerts disabled.'));
    }

    public function runScheduleNow(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        match ($schedule->target_type) {
            ServerBackupSchedule::TARGET_DATABASE => $this->dispatchScheduleDatabase($schedule),
            ServerBackupSchedule::TARGET_SITE_FILES => $this->dispatchScheduleSiteFiles($schedule),
            default => $this->toastError(__('Unknown target type.')),
        };

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.run_now', $schedule);
        }
    }

    private function dispatchScheduleDatabase(ServerBackupSchedule $schedule): void
    {
        $database = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($schedule->target_id)
            ->first();
        if ($database === null) {
            $this->toastError(__('Schedule target database is missing.'));

            return;
        }

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);
        ExportServerDatabaseBackupJob::dispatch($backup->id);
        $this->toastSuccess(__('Backup queued for :name.', ['name' => $database->name]));
    }

    private function dispatchScheduleSiteFiles(ServerBackupSchedule $schedule): void
    {
        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($schedule->target_id)
            ->first();
        if ($site === null) {
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

    /**
     * Pause/resume a schedule by flipping is_active on both the schedule row and the
     * backing cron entry. The cron line stays in place so resume is one click.
     */
    public function toggleSchedule(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $newActive = ! $schedule->is_active;
        $schedule->update(['is_active' => $newActive]);
        if ($schedule->server_cron_job_id) {
            ServerCronJob::query()->whereKey($schedule->server_cron_job_id)->update(['enabled' => $newActive]);
        }

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                $newActive ? 'backup.schedule.resumed' : 'backup.schedule.paused',
                $schedule,
            );
        }

        $this->toastSuccess($newActive ? __('Schedule resumed.') : __('Schedule paused.'));
    }

    public function deleteDatabaseBackup(string $backupId): void
    {
        $this->authorize('update', $this->server);

        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->first();
        if ($backup === null) {
            return;
        }

        if (! empty($backup->disk_path)) {
            $disk = Storage::disk(config('server_database.backup_disk', 'local'));
            if ($disk->exists($backup->disk_path)) {
                $disk->delete($backup->disk_path);
            }
        }
        $snapshot = [
            'backup_id' => (string) $backup->id,
            'server_database_id' => (string) $backup->server_database_id,
            'disk_path' => $backup->disk_path,
            'status' => $backup->status,
        ];
        $backup->delete();

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.database.deleted', $this->server, $snapshot, null);
        }

        $this->toastSuccess(__('Backup deleted.'));
    }

    public function deleteFileBackup(string $backupId): void
    {
        $this->authorize('update', $this->server);

        $backup = SiteFileBackup::query()
            ->whereKey($backupId)
            ->whereHas('site', fn ($q) => $q->where('server_id', $this->server->id))
            ->first();
        if ($backup === null) {
            return;
        }

        if (! empty($backup->disk_path) && Storage::disk('local')->exists($backup->disk_path)) {
            Storage::disk('local')->delete($backup->disk_path);
        }
        $snapshot = [
            'backup_id' => (string) $backup->id,
            'site_id' => (string) $backup->site_id,
            'disk_path' => $backup->disk_path,
            'status' => $backup->status,
        ];
        $backup->delete();

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.site_files.deleted', $this->server, $snapshot, null);
        }

        $this->toastSuccess(__('Backup deleted.'));
    }

    public function render(): View
    {
        $this->server->refresh();

        // Note: $databases/$sites are NOT narrowed by context_site_id — the new-schedule form
        // still needs them all to pick from. The query collections below DO narrow so the
        // operator only sees runs / schedules / stats for the focused site.
        $databases = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->orderBy('name')
            ->get();

        $sites = Site::query()
            ->where('server_id', $this->server->id)
            ->orderBy('name')
            ->get();

        // When filtered to a single site, database backups go away entirely (databases are
        // server-scoped, not site-scoped) — we narrow $databaseIds to an empty list so the
        // DB run list reads as "none for this site" rather than the full server fleet.
        $databaseIds = $this->context_site_id !== null ? [] : $databases->pluck('id')->all();
        $siteIds = $this->context_site_id !== null
            ? [$this->context_site_id]
            : $sites->pluck('id')->all();

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

        return view('livewire.servers.workspace-backups', [
            'opsReady' => $this->serverOpsReady(),
            'contextSite' => $contextSite,
            'databases' => $databases,
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
        ]);
    }
}
