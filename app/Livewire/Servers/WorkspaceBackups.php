<?php

namespace App\Livewire\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesBackupDestinationModal;
use App\Livewire\Servers\Concerns\ManagesBackupNotifications;
use App\Models\BackupConfiguration;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Notifications\BackupFailureNotification;
use App\Services\Servers\DatabaseBackupDownloader;
use App\Services\Servers\DatabaseBackupExporter;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\DatabaseBackupSettings;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backups workspace at {@see servers.backups} (server-wide) and {@see sites.backups}
 * (site-scoped). Surfaces {@see ServerDatabaseBackup} and {@see SiteFileBackup}
 * runs plus recurring schedule CRUD via {@see ServerBackupSchedule}.
 *
 * Schedules materialize as {@see ServerCronJob} entries that invoke
 * `dply:run-backup-schedule {schedule}` so the cron line stays stable across
 * cadence edits.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceBackups extends Component
{
    use RendersWorkspacePlaceholder;
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesBackupDestinationModal;
    use ManagesBackupNotifications;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.backups';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    /** Form state for "Run database backup now". */
    public string $run_database_id = '';

    /** Optional one-off S3 destination override (empty = server default). */
    public string $run_database_backup_configuration_id = '';

    /** Server default: remote_server or destination. */
    public string $db_backup_default_kind = DatabaseBackupSettings::KIND_REMOTE_SERVER;

    public string $db_backup_configuration_id = '';

    /** Display field (GB); persisted as bytes in server meta. */
    public string $db_backup_remote_max_gb = '';

    /** Form state for "Run site files backup now". */
    public string $run_site_id = '';

    /** New schedule form. */
    public string $new_target_type = ServerBackupSchedule::TARGET_DATABASE;

    public string $new_target_id = '';

    public string $new_cron_expression = '0 3 * * *';

    public ?string $new_backup_configuration_id = null;

    /** When set (site route or ?site=), all queries narrow to that site. */
    public ?string $context_site_id = null;

    /** True when mounted from {@see sites.backups} (native site workspace, not a server filter). */
    public bool $siteDedicatedContext = false;

    /** overview | schedules | history */
    public string $backups_workspace_tab = 'overview';

    public function setBackupsWorkspaceTab(string $tab): void
    {
        $this->backups_workspace_tab = in_array($tab, ['overview', 'schedules', 'history', 'notifications'], true) ? $tab : 'overview';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->backups_workspace_tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }

    public function mount(Server $server, ?Site $site = null): void
    {
        if ($site !== null) {
            abort_unless($site->server_id === $server->id, 404);
            abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);
            Gate::authorize('view', $site);

            $this->siteDedicatedContext = true;
            $this->context_site_id = $site->id;
            $this->new_target_type = ServerBackupSchedule::TARGET_SITE_FILES;
            $this->new_target_id = $site->id;
        }

        if (! Feature::active('workspace.backups')) {
            if (workspace_backups_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->bootWorkspace($server);
        $this->destinationForm = $this->emptyDestinationForm();
        $this->hydrateDatabaseBackupSettings();

        if ($this->context_site_id !== null) {
            return;
        }

        // Server workspace can deep-link with ?site= to pre-filter without leaving server nav.
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

        // Deep-link from surfaces that need a destination but have none yet (e.g.
        // the Snapshots → Cache empty state): ?add_destination=1 lands here with
        // the "Add backup destination" modal already open.
        if (request()->boolean('add_destination') && Gate::allows('create', BackupConfiguration::class)) {
            $this->openDestinationModal();
        }
    }

    /**
     * Auto-select the freshly-created destination so the operator's next action
     * is to submit the schedule, not to scroll-find the entry they just made.
     */
    protected function onBackupDestinationCreated(BackupConfiguration $destination): void
    {
        $this->new_backup_configuration_id = $destination->id;
        $this->db_backup_configuration_id = $destination->id;
    }

    public function saveDatabaseBackupSettings(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'db_backup_default_kind' => 'required|in:remote_server,destination',
            'db_backup_configuration_id' => 'nullable|string',
            'db_backup_remote_max_gb' => 'nullable|numeric|min:0.1|max:10000',
        ]);

        if ($this->db_backup_default_kind === DatabaseBackupSettings::KIND_DESTINATION && $this->db_backup_configuration_id === '') {
            $this->addError('db_backup_configuration_id', __('Pick a backup destination when using S3 storage.'));

            return;
        }

        $maxBytes = null;
        if ($this->db_backup_remote_max_gb !== '') {
            $maxBytes = (int) round((float) $this->db_backup_remote_max_gb * 1024 * 1024 * 1024);
        }

        $settings = new DatabaseBackupSettings(
            defaultKind: $this->db_backup_default_kind,
            backupConfigurationId: $this->db_backup_configuration_id !== '' ? $this->db_backup_configuration_id : null,
            remoteMaxBytes: $maxBytes,
        );

        $meta = $this->server->meta ?? [];
        $meta['database_backup'] = $settings->toMetaArray();
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();

        $this->toastSuccess(__('Database backup storage settings saved.'));
    }

    protected function hydrateDatabaseBackupSettings(): void
    {
        $settings = DatabaseBackupSettings::fromServer($this->server);
        $this->db_backup_default_kind = $settings->defaultKind;
        $this->db_backup_configuration_id = $settings->backupConfigurationId ?? '';
        $bytes = $settings->remoteMaxBytes ?? (int) config('server_database.remote_backup_max_bytes_per_server', 10 * 1024 * 1024 * 1024);
        $this->db_backup_remote_max_gb = (string) round($bytes / 1024 / 1024 / 1024, 1);
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

        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $this->server,
            $this->run_database_backup_configuration_id !== '' ? $this->run_database_backup_configuration_id : null,
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id);

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.database.run_dispatched', $this->server, null, [
                'backup_id' => (string) $backup->id,
                'database_id' => (string) $database->id,
                'database_name' => $database->name,
            ]);
        }

        $this->dispatchBackupNotification('run_started', [__('Database — :name', ['name' => $database->name])], [
            'backup_type' => 'database',
            'backup_id' => (string) $backup->id,
            'database_id' => (string) $database->id,
        ]);

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

        $this->dispatchBackupNotification('run_started', [__('Site files — :name', ['name' => $site->name])], [
            'backup_type' => 'site_files',
            'backup_id' => (string) $backup->id,
            'site_id' => (string) $site->id,
        ]);

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

        $this->dispatchBackupNotification('schedule_created', [$schedule->targetLabel()], [
            'schedule_id' => (string) $schedule->id,
            'target_type' => $schedule->target_type,
            'cron_expression' => $schedule->cron_expression,
        ]);

        $this->reset(['new_target_id', 'new_backup_configuration_id']);
        $this->new_cron_expression = '0 3 * * *';
        $this->toastSuccess(__('Backup schedule added.'));
    }

    public function downloadDatabaseBackup(string $backupId, DatabaseBackupDownloader $downloader): StreamedResponse|Response|null
    {
        $this->authorize('update', $this->server);
        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->with('serverDatabase')
            ->firstOrFail();

        $extension = $backup->serverDatabase?->engine === 'sqlite' ? 'db' : 'sql';
        $filename = ($backup->serverDatabase?->name ?? 'database').'-'.$backup->id.'.'.$extension;

        try {
            return $downloader->response($backup, $filename);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return null;
        }
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

        $scheduleLabel = $schedule->targetLabel();
        $scheduleCron = $schedule->cron_expression;
        $schedule->delete();
        $this->dispatchBackupNotification('schedule_deleted', [$scheduleLabel], [
            'schedule_id' => $scheduleId,
            'cron_expression' => $scheduleCron,
        ]);
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

        $this->dispatchBackupNotification('schedule_updated', [$schedule->targetLabel()], [
            'schedule_id' => (string) $schedule->id,
            'change' => 'cadence',
            'cron_expression' => $newCron,
            'previous_cron_expression' => $oldCron,
        ]);

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

        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $this->server,
            $schedule->backup_configuration_id,
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id);
        $this->dispatchBackupNotification('run_started', [__('Database — :name', ['name' => $database->name])], [
            'backup_type' => 'database',
            'backup_id' => (string) $backup->id,
            'database_id' => (string) $database->id,
            'scheduled' => true,
        ]);
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
        $this->dispatchBackupNotification('run_started', [__('Site files — :name', ['name' => $site->name])], [
            'backup_type' => 'site_files',
            'backup_id' => (string) $backup->id,
            'site_id' => (string) $site->id,
            'scheduled' => true,
        ]);
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

        $this->dispatchBackupNotification('schedule_updated', [$schedule->targetLabel()], [
            'schedule_id' => (string) $schedule->id,
            'change' => $newActive ? 'resumed' : 'paused',
        ]);

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

        app(DatabaseBackupExporter::class)->deleteArtifact($backup);
        $snapshot = [
            'backup_id' => (string) $backup->id,
            'server_database_id' => (string) $backup->server_database_id,
            'storage_kind' => $backup->storage_kind,
            'status' => $backup->status,
        ];
        $backup->delete();

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.database.deleted', $this->server, $snapshot, null);
        }

        $this->dispatchBackupNotification('deleted', [__('Database backup')], [
            'backup_type' => 'database',
            'backup_id' => $snapshot['backup_id'],
            'server_database_id' => $snapshot['server_database_id'],
        ]);

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

        $this->dispatchBackupNotification('deleted', [__('Site files backup')], [
            'backup_type' => 'site_files',
            'backup_id' => $snapshot['backup_id'],
            'site_id' => $snapshot['site_id'],
        ]);

        $this->toastSuccess(__('Backup deleted.'));
    }

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
            'siteDedicatedContext' => $this->siteDedicatedContext,
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
            'notifChannels' => $this->backups_workspace_tab === 'notifications' ? $this->assignableBackupNotificationChannels() : collect(),
            'notifSubscriptions' => $this->backups_workspace_tab === 'notifications' ? $this->backupNotificationSubscriptions() : collect(),
            'notifEventLabels' => $this->backups_workspace_tab === 'notifications' ? $this->backupEventLabels() : [],
        ]);
    }
}
