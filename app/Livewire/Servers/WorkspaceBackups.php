<?php

namespace App\Livewire\Servers;

use App\Modules\Backups\Jobs\ExportServerDatabaseBackupJob;
use App\Modules\Backups\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\QueuesQuickDownloads;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Concerns\StagesBackupDownloads;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\BuildsServerBackupView;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesBackupDestinationModal;
use App\Livewire\Servers\Concerns\ManagesServerBackupDatabases;
use App\Livewire\Servers\Concerns\ManagesServerBackupRuns;
use App\Livewire\Servers\Concerns\ManagesServerBackupSchedules;
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
use App\Modules\Backups\Models\SiteFileBackup;
use App\Notifications\BackupFailureNotification;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Modules\Backups\Services\SiteFileBackupExporter;
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
    use BuildsServerBackupView;
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use DismissesServerConsoleActionRun;
    use ManagesServerBackupDatabases;
    use ManagesServerBackupRuns;
    use ManagesServerBackupSchedules;
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


    /** Inline-edit form state: schedule id → new cron expression. Empty = not editing. */
    public array $editing_schedules = [];


}
