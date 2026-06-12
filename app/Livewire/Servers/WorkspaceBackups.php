<?php

namespace App\Livewire\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\QueuesQuickDownloads;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Concerns\StagesBackupDownloads;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesBackupDestinationModal;
use App\Livewire\Servers\Concerns\ManagesBackupNotifications;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerConsoleActions;
use App\Models\BackupConfiguration;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SiteFileBackup;
use App\Notifications\BackupFailureNotification;
use App\Services\Servers\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SiteFileBackupExporter;
use App\Support\Servers\DatabaseBackupSettings;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

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
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use DismissesServerConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesBackupDestinationModal;
    use ManagesBackupNotifications;
    use QueuesQuickDownloads;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;
    use RunsServerConsoleActions;
    use StagesBackupDownloads;

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

    /** Display value for the remote-disk cap; unit in {@see $db_backup_remote_max_unit}. Persisted as bytes. */
    public string $db_backup_remote_max_value = '';

    /** MB | GB — unit for {@see $db_backup_remote_max_value}. */
    public string $db_backup_remote_max_unit = 'GB';

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

    /**
     * Session-scoped, one-shot tracking for an on-demand backup the operator
     * just launched, so the banner-driven completion can guard-jump them to
     * History. These are deliberately NOT rehydrated on mount — the banner +
     * button "Running…" state derive from the DB, but the jump only arms for a
     * run started in THIS session (see {@see pollBackupRun()}).
     */
    public ?string $watchedBackupRunId = null;

    /** The backup row to highlight in History when the watched run completes. */
    public ?string $watchedBackupId = null;

    /** 'database' | 'site_files' — which History list the watched row lives in. */
    public ?string $watchedBackupType = null;

    /** The tab the run was launched from; the jump only fires if still here. */
    public ?string $originatingBackupTab = null;

    /** Set on a successful watched run → flashes + scrolls the History row. */
    public ?string $highlightBackupId = null;

    public ?string $highlightBackupType = null;

    /**
     * Live databases discovered ON THE BOX for the Quick-download card — including
     * ones dply never catalogued as a {@see ServerDatabase} row. Populated off the
     * render path via {@see detectLiveDatabases()} (wire:init), so a server whose
     * database was created outside dply can still be dumped. Each entry is
     * ['engine' => string, 'name' => string].
     *
     * @var list<array{engine: string, name: string}>
     */
    public array $liveDbDumpTargets = [];

    /** True once {@see detectLiveDatabases()} has run (drives the card's spinner). */
    public bool $liveDbDetected = false;

    public function setBackupsWorkspaceTab(string $tab): void
    {
        $this->backups_workspace_tab = in_array($tab, ['overview', 'schedules', 'history', 'notifications'], true) ? $tab : 'overview';
    }

    /**
     * Seed a console-action row for an on-demand backup run and arm the
     * session-scoped, one-shot completion watch. Subject is THIS server (where
     * the banner renders), the originating tab is whatever the operator clicked
     * from. Returns the run id to hand to the export job.
     */
    private function startBackupConsoleRun(string $kind, string $label, string $backupId, string $type): string
    {
        $runId = $this->seedConsoleActionRun($this->server, $kind, $label);

        $this->watchedBackupRunId = $runId;
        $this->watchedBackupId = $backupId;
        $this->watchedBackupType = $type;
        $this->originatingBackupTab = $this->backups_workspace_tab;

        // A fresh run supersedes any prior highlight so an old flash doesn't
        // linger over the wrong row.
        $this->highlightBackupId = null;
        $this->highlightBackupType = null;

        return $runId;
    }

    /**
     * Poll hook (active only while {@see $watchedBackupRunId} is set) for the
     * one-shot guard-jump. On success → jump to History + flash the row, but
     * only if still on the originating tab (no focus hijack). On failure → an
     * error toast; the banner already streams the error, so no jump. A vanished
     * or dismissed run just disarms the watch.
     */
    public function pollBackupRun(): void
    {
        if ($this->watchedBackupRunId === null) {
            return;
        }

        $run = ConsoleAction::query()
            ->whereKey($this->watchedBackupRunId)
            ->first(['id', 'status', 'dismissed_at']);

        // Dismissed mid-run (operator said "done watching") or gone → disarm.
        if ($run === null || $run->dismissed_at !== null) {
            $this->disarmBackupWatch();

            return;
        }

        if (! in_array($run->status, [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], true)) {
            return; // still queued/running
        }

        $stillOnOriginatingTab = $this->backups_workspace_tab === $this->originatingBackupTab;

        if ($run->status === ConsoleAction::STATUS_COMPLETED) {
            if ($stillOnOriginatingTab) {
                // Jump + flash; the History row's x-init scrolls itself into view.
                $this->highlightBackupId = $this->watchedBackupId;
                $this->highlightBackupType = $this->watchedBackupType;
                $this->backups_workspace_tab = 'history';
            } else {
                // They navigated away — don't yank them; just confirm.
                $this->toastSuccess(__('Backup complete — it’s in History.'));
            }
        } else { // failed
            $this->toastError(__('Backup failed — see the console banner for details.'));
        }

        $this->disarmBackupWatch();
    }

    private function disarmBackupWatch(): void
    {
        $this->watchedBackupRunId = null;
        $this->watchedBackupId = null;
        $this->watchedBackupType = null;
        $this->originatingBackupTab = null;
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
            'db_backup_remote_max_value' => 'nullable|numeric|min:1|max:1048576',
            'db_backup_remote_max_unit' => 'required|in:MB,GB',
        ]);

        if ($this->db_backup_default_kind === DatabaseBackupSettings::KIND_DESTINATION && $this->db_backup_configuration_id === '') {
            $this->addError('db_backup_configuration_id', __('Pick a backup destination when using S3 storage.'));

            return;
        }

        $maxBytes = null;
        if ($this->db_backup_remote_max_value !== '') {
            $factor = $this->db_backup_remote_max_unit === 'MB' ? 1024 * 1024 : 1024 * 1024 * 1024;
            $maxBytes = (int) round((float) $this->db_backup_remote_max_value * $factor);
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
        // Show sub-GB caps in MB so small values aren't awkward decimals (0.1 GB → 100 MB).
        if ($bytes < 1024 * 1024 * 1024) {
            $this->db_backup_remote_max_unit = 'MB';
            $this->db_backup_remote_max_value = (string) max(1, (int) round($bytes / 1024 / 1024));
        } else {
            $this->db_backup_remote_max_unit = 'GB';
            $this->db_backup_remote_max_value = (string) round($bytes / 1024 / 1024 / 1024, 1);
        }
    }

    public function runDatabaseBackup(): void
    {
        $this->authorize('update', $this->server);

        // Site context only runs databases linked to the focused site.
        $siteScope = $this->siteDedicatedContext ? $this->context_site_id : null;

        $database = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->when($siteScope !== null, fn ($q) => $q->where('site_id', $siteScope))
            ->whereKey($this->run_database_id)
            ->first();

        // Fall back to a database hosted on another server that a site here
        // attaches to. Its backup still runs on its OWN home server (the export
        // job dumps where $db->server lives), so this is purely a surfacing
        // convenience — same org + binding constraints enforced by the helper.
        if ($database === null) {
            $database = $this->remoteAttachedDatabases()->firstWhere('id', $this->run_database_id);
        }

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

        $runId = $this->startBackupConsoleRun(
            'backup_database',
            __('Database — :name', ['name' => $database->name]),
            (string) $backup->id,
            'database',
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id, $runId);

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
        // No "queued" toast — the console banner now streams queued → running.
        $this->dispatch('dply-console-action-focus');
    }

    /**
     * Databases that sites on THIS server attach to but which are hosted on a
     * DIFFERENT server (remote bindings). They're still ServerDatabase rows, so
     * a backup of one runs — correctly — on its own home server: the export job
     * resolves $db->server and dumps over that box's local socket. We surface
     * them here as a convenience for the consuming server. Scoped to the same
     * organization and, in site context, to the focused site's bindings.
     *
     * @return Collection<int, ServerDatabase>
     */
    private function remoteAttachedDatabases(): Collection
    {
        $siteScope = $this->siteDedicatedContext ? $this->context_site_id : null;

        $targetIds = SiteBinding::query()
            ->where('target_type', 'server_database')
            ->whereHas('site', fn ($q) => $q
                ->where('server_id', $this->server->id)
                ->when($siteScope !== null, fn ($q2) => $q2->whereKey($siteScope)))
            ->pluck('target_id')
            ->filter()
            ->unique()
            ->all();

        if ($targetIds === []) {
            return collect();
        }

        return ServerDatabase::query()
            ->whereIn('id', $targetIds)
            ->where('server_id', '!=', $this->server->id)
            ->whereHas('server', fn ($q) => $q->where('organization_id', $this->server->organization_id))
            ->with('server')
            ->orderBy('name')
            ->get();
    }

    /**
     * Resolve a database backup this server's workspace may act on: its database
     * is hosted here, OR it's one a site here attaches to remotely (the same set
     * surfaced in history). Returns null when not found or not allowed — so a
     * remote-attached backup no longer 404s the download/delete action. The
     * downloader streams from the database's own home server regardless.
     */
    private function resolveDatabaseBackupForServer(string $backupId): ?ServerDatabaseBackup
    {
        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->with('serverDatabase.server')
            ->first();

        $db = $backup?->serverDatabase;
        if ($db === null) {
            return null;
        }

        $allowed = (string) $db->server_id === (string) $this->server->id
            || $this->remoteAttachedDatabases()->contains('id', $db->id);

        return $allowed ? $backup : null;
    }

    public function runSiteFilesBackup(): void
    {
        $this->authorize('update', $this->server);

        // Site context can only run the focused site (the second whereKey pins
        // it, so a crafted run_site_id for another site finds no match).
        $siteScope = $this->siteDedicatedContext ? $this->context_site_id : null;

        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->when($siteScope !== null, fn ($q) => $q->whereKey($siteScope))
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

        $runId = $this->startBackupConsoleRun(
            'backup_site_files',
            __('Site files — :name', ['name' => $site->name]),
            (string) $backup->id,
            'site_files',
        );

        ExportSiteFileBackupJob::dispatch($backup->id, $runId);

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
        // No "queued" toast — the console banner now streams queued → running.
        $this->dispatch('dply-console-action-focus');
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

        // In site-dedicated context the target must belong to the focused site
        // (the picker only offers this site + its linked databases) — enforce it
        // server-side so a crafted request can't schedule another site's backup.
        $siteScope = $this->siteDedicatedContext ? $this->context_site_id : null;

        $exists = match ($this->new_target_type) {
            ServerBackupSchedule::TARGET_DATABASE => ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->when($siteScope !== null, fn ($q) => $q->where('site_id', $siteScope))
                ->whereKey($this->new_target_id)
                ->exists(),
            ServerBackupSchedule::TARGET_SITE_FILES => Site::query()
                ->where('server_id', $this->server->id)
                ->when($siteScope !== null, fn ($q) => $q->whereKey($siteScope))
                ->whereKey($this->new_target_id)
                ->exists(),
            default => false,
        };
        if (! $exists) {
            $this->toastError($siteScope !== null
                ? __('Pick a target that belongs to this site.')
                : __('Target not found on this server.'));

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

    /**
     * Off-render-path probe (wire:init) that lists databases actually present on
     * the server — registered or not — so the Quick-download card can offer an
     * instant dump of each. Mirrors the drift analyzer's live listing. Runs in
     * server context only; site context already scopes to linked databases. Never
     * throws into the render path — engine probe failures degrade to an empty list.
     */
    public function detectLiveDatabases(
        ServerDatabaseHostCapabilities $capabilities,
        ServerDatabaseProvisioner $provisioner,
    ): void {
        $this->liveDbDetected = true;

        if ($this->context_site_id !== null) {
            return;
        }

        $this->authorize('update', $this->server);

        $caps = $capabilities->forServer($this->server);
        $targets = [];

        if (($caps['mysql'] ?? false) || ($caps['mariadb'] ?? false)) {
            try {
                foreach ($provisioner->listMysqlDatabaseNames($this->server) as $name) {
                    $targets[] = ['engine' => 'mysql', 'name' => $name];
                }
            } catch (\Throwable) {
                // ignore — engine present but unreachable
            }
        }

        if ($caps['postgres'] ?? false) {
            try {
                foreach ($provisioner->listPostgresDatabaseNames($this->server) as $name) {
                    $targets[] = ['engine' => 'postgres', 'name' => $name];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $this->liveDbDumpTargets = $targets;
    }

    /**
     * Resolve + authorize a backup for the Hetzner staging download flow. DB
     * backups allow remote-attached databases (their dump runs on the home
     * server); site-file backups are scoped to this server's sites.
     */
    protected function resolveDownloadableBackup(string $type, string $backupId): ?Model
    {
        $this->authorize('update', $this->server);

        return match ($type) {
            'database' => $this->resolveDatabaseBackupForServer($backupId),
            'site_files' => SiteFileBackup::query()
                ->whereKey($backupId)
                ->whereHas('site', fn ($q) => $q->where('server_id', $this->server->id))
                ->with('site.server')
                ->first(),
            default => null,
        };
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

        $runId = $this->startBackupConsoleRun(
            'backup_database',
            __('Database — :name', ['name' => $database->name]),
            (string) $backup->id,
            'database',
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id, $runId);
        $this->dispatchBackupNotification('run_started', [__('Database — :name', ['name' => $database->name])], [
            'backup_type' => 'database',
            'backup_id' => (string) $backup->id,
            'database_id' => (string) $database->id,
            'scheduled' => true,
        ]);
        $this->dispatch('dply-console-action-focus');
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
        $runId = $this->startBackupConsoleRun(
            'backup_site_files',
            __('Site files — :name', ['name' => $site->name]),
            (string) $backup->id,
            'site_files',
        );

        ExportSiteFileBackupJob::dispatch($backup->id, $runId);
        $this->dispatchBackupNotification('run_started', [__('Site files — :name', ['name' => $site->name])], [
            'backup_type' => 'site_files',
            'backup_id' => (string) $backup->id,
            'site_id' => (string) $site->id,
            'scheduled' => true,
        ]);
        $this->dispatch('dply-console-action-focus');
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

        $backup = $this->resolveDatabaseBackupForServer($backupId);
        if ($backup === null) {
            return;
        }

        app(DatabaseBackupExporter::class)->deleteArtifact($backup);
        $this->purgeBackupStagings($backup);
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

        app(SiteFileBackupExporter::class)->deleteArtifact($backup);
        $this->purgeBackupStagings($backup);
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
