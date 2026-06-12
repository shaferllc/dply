<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\AuthorsBackupDestinations;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\SurfacesBindingConsumers;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesDatabaseAdminCredentials;
use App\Livewire\Servers\Concerns\ManagesDatabaseBackups;
use App\Livewire\Servers\Concerns\ManagesDatabaseCredentialModals;
use App\Livewire\Servers\Concerns\ManagesDatabaseCrud;
use App\Livewire\Servers\Concerns\ManagesDatabaseEdit;
use App\Livewire\Servers\Concerns\ManagesDatabaseEngineLifecycle;
use App\Livewire\Servers\Concerns\ManagesDatabaseExtras;
use App\Livewire\Servers\Concerns\ManagesDatabaseNotifications;
use App\Livewire\Servers\Concerns\ManagesDatabaseSqliteConsole;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Livewire\Servers\Concerns\RunsServerConsoleActions;
use App\Models\BackupConfiguration;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\ServerDatabaseEngine;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\DatabaseWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceDatabases extends Component
{
    use AuthorsBackupDestinations;
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use DismissesServerConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesDatabaseAdminCredentials;
    use ManagesDatabaseBackups;
    use ManagesDatabaseCredentialModals;
    use ManagesDatabaseCrud;
    use ManagesDatabaseEdit;
    use ManagesDatabaseEngineLifecycle;
    use ManagesDatabaseExtras;
    use ManagesDatabaseNotifications;
    use ManagesDatabaseSqliteConsole;
    use RendersWorkspacePlaceholder;
    use RunsAllowlistedManageAction;
    use RunsServerConsoleActions;
    use SurfacesBindingConsumers;
    use WithFileUploads;

    #[Url(as: 'tab', except: 'databases', history: true)]
    public string $workspace_tab = 'databases';

    protected bool $driftProbeErrorNotified = false;

    public function boot(): void
    {
        $this->share_expires_hours = (int) config('server_database.credential_share_expires_hours', 72);
        $this->share_max_views = (int) config('server_database.credential_share_max_views', 3);
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $server->load('databaseAdminCredential');
        $ac = $server->databaseAdminCredential;
        if ($ac) {
            $this->admin_mysql_root_username = $ac->mysql_root_username;
            $this->admin_postgres_superuser = $ac->postgres_superuser;
            $this->admin_postgres_use_sudo = $ac->postgres_use_sudo;
            $this->admin_mongodb_username = $ac->mongodb_admin_username ?: 'admin';
            $this->admin_clickhouse_username = $ac->clickhouse_admin_username ?: 'default';
        }

        $meta = $server->meta ?? [];
        $this->manage_db_bind_host = (string) ($meta['manage_db_bind_host'] ?? '');
        $port = $meta['manage_db_port'] ?? null;
        $this->manage_db_port = is_numeric($port) ? (int) $port : null;
    }

    /**
     * Sites consuming this server's databases, grouped by database id — the
     * "Used by" list on the Connections subtab. One query for the whole tab.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    #[Computed]
    public function databaseConsumers(): array
    {
        return $this->buildBindingConsumers(
            'server_database',
            $this->server->serverDatabases->pluck('id')->all(),
            $this->server->id,
        );
    }

    /**
     * Per-engine sub-tab — flips between the management surface (`overview`) and
     * the engine information card (`info`) inside each per-engine tab panel.
     * Mirrors the same pattern used by WorkspaceCaches + WorkspaceWebserver so
     * operators learn one navigation idiom across workspaces.
     */
    #[Url(as: 'subtab', except: 'overview', history: true)]
    public string $engine_subtab = 'overview';

    /** @var list<string> */
    public const ENGINE_SUBTABS = ['overview', 'databases', 'admin', 'networking', 'connections', 'backups', 'extensions', 'info', 'danger'];

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = DatabaseWorkspaceEngines::WORKSPACE_TABS;
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'databases';
        // Reset sub-tab on every top-level switch so engines always open on the
        // actionable view — without this the operator clicking from MySQL→Info
        // then Postgres would land on Postgres→Info, hiding the actions.
        $this->engine_subtab = 'overview';
        $this->engine_create_form_open = false;
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->workspace_tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    public function setEngineSubtab(string $subtab): void
    {
        $this->engine_subtab = in_array($subtab, self::ENGINE_SUBTABS, true) ? $subtab : 'overview';
    }

    /** S3-compatible providers — the only destinations the database exporter can upload to. */
    public const S3_BACKUP_PROVIDERS = [
        BackupConfiguration::PROVIDER_AWS_S3,
        BackupConfiguration::PROVIDER_CUSTOM_S3,
        BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES,
    ];

    public function render(): View
    {
        $allowedTabs = DatabaseWorkspaceEngines::WORKSPACE_TABS;
        if (! in_array($this->workspace_tab, $allowedTabs, true)) {
            $this->workspace_tab = 'databases';
        }

        $tab = $this->workspace_tab;
        $needsBasics = $tab === 'databases';
        $needsAdvanced = $tab === 'advanced';
        $needsNotifications = $tab === 'notifications';
        $needsEngine = in_array($tab, DatabaseWorkspaceEngines::ENGINE_TABS, true);
        $activeEngine = $needsEngine ? $tab : null;

        if ($needsBasics || $needsEngine) {
            $this->server->loadMissing(['serverDatabases.extraUsers']);
        }

        if ($needsAdvanced) {
            $this->server->loadMissing([
                'serverDatabases',
                'databaseAuditEvents' => fn ($q) => $q->with('user:id,name')->limit(80),
            ]);
        }

        // Engine capabilities are one SSH probe; they are loaded off the render path via
        // wire:init (loadCapabilities) so the page paints instantly. Until that resolves we
        // render all-false and views key off $capabilitiesLoaded to show a "checking…" state
        // rather than a misleading "not installed".
        $capabilities = $this->capabilitiesLoaded
            ? ($this->capabilities_state ?: DatabaseWorkspaceEngines::defaultCapabilities())
            : DatabaseWorkspaceEngines::defaultCapabilities();

        if ($needsBasics && ! ($capabilities[$this->new_db_engine] ?? false)) {
            foreach (DatabaseWorkspaceEngines::ENGINE_TABS as $engine) {
                if ($capabilities[$engine] ?? false) {
                    $this->new_db_engine = $engine;
                    break;
                }
            }
        }

        $engineRows = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->get()
            ->keyBy('engine');

        // Drift analysis is 4 sequential SSH round-trips (one per engine family); it is
        // deferred to wire:init via loadDriftSnapshot() on the Connections subtab so it
        // never blocks first paint. The drift card shows a "checking…" state until then.

        $credentialsModalDatabase = null;
        if ($this->credentials_modal_db_id !== null) {
            $credentialsModalDatabase = ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->find($this->credentials_modal_db_id);
        }

        $connectionUrlModalDatabase = null;
        if ($this->connection_url_modal_db_id !== null) {
            $connectionUrlModalDatabase = ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->find($this->connection_url_modal_db_id);
        }

        $backupModalDatabase = null;
        $backupS3Destinations = collect();
        if ($this->backup_modal_db_id !== null) {
            $backupModalDatabase = ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->find($this->backup_modal_db_id);
            $backupS3Destinations = $this->s3BackupDestinations();
        }

        $recentBackupsByEngine = collect();
        if ($needsEngine) {
            $recentBackupsByEngine = ServerDatabaseBackup::query()
                ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
                ->with('serverDatabase')
                ->orderByDesc('created_at')
                ->limit(60)
                ->get()
                ->groupBy(fn ($b) => $b->serverDatabase?->engine ?? 'unknown');
        }

        $orgAllowsCredentialShares = true;
        $databaseImportMaxBytes = (int) config('server_database.import_max_bytes', 10485760);
        if ($needsEngine) {
            $this->server->loadMissing('organization');
            $org = $this->server->organization;
            $orgAllowsCredentialShares = $org ? $org->allowsDatabaseCredentialShares() : true;
            $databaseImportMaxBytes = $org
                ? $org->databaseImportMaxBytes()
                : $databaseImportMaxBytes;
        }

        $databaseConsoleBannerRun = null;
        if ($needsEngine) {
            foreach (array_merge(DatabaseWorkspaceEngines::MYSQL_FAMILY, ['postgres', 'mongodb', 'clickhouse']) as $engine) {
                $row = $engineRows->get($engine);
                if ($row === null) {
                    continue;
                }
                $run = $this->latestConsoleActionFor($row, 'db_');
                if ($run !== null && ($databaseConsoleBannerRun === null || $run->created_at > $databaseConsoleBannerRun->created_at)) {
                    $databaseConsoleBannerRun = $run;
                }
            }

            $sqliteRun = $this->latestConsoleActionFor($this->server, 'db_');
            if ($sqliteRun !== null && ($databaseConsoleBannerRun === null || $sqliteRun->created_at > $databaseConsoleBannerRun->created_at)) {
                $databaseConsoleBannerRun = $sqliteRun;
            }
        }

        $manageActionRun = null;
        if (DatabaseWorkspaceEngines::isMysqlFamily((string) $activeEngine) && $this->engine_subtab === 'info') {
            $manageActionRun = ConsoleAction::query()
                ->where('subject_type', $this->server->getMorphClass())
                ->where('subject_id', $this->server->id)
                ->where('kind', 'manage_action')
                ->whereNull('dismissed_at')
                ->orderByDesc('created_at')
                ->first();
        }

        return view('livewire.servers.workspace-databases', array_merge(
            DatabaseWorkspaceViewData::for(
                $this->server,
                $this,
                $engineRows,
                $capabilities,
                $needsAdvanced,
            ),
            [
                'capabilitiesLoaded' => $this->capabilitiesLoaded,
                'credentialsModalDatabase' => $credentialsModalDatabase,
                'backupModalDatabase' => $backupModalDatabase,
                'backupS3Destinations' => $backupS3Destinations,
                'connectionUrlModalDatabase' => $connectionUrlModalDatabase,
                'existingMysqlUserOptions' => $needsBasics ? $this->existingMysqlUserOptions() : [],
                'recentBackupsByEngine' => $recentBackupsByEngine,
                'orgAllowsCredentialShares' => $orgAllowsCredentialShares,
                'databaseImportMaxBytes' => $databaseImportMaxBytes,
                'databaseConsoleBannerRun' => $databaseConsoleBannerRun,
                'serviceActions' => config('server_manage.service_actions', []),
                'manageActionRun' => $manageActionRun,
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
                'notifChannels' => $needsNotifications ? $this->assignableDatabaseNotificationChannels() : collect(),
                'notifSubscriptions' => $needsNotifications ? $this->databaseNotificationSubscriptions() : collect(),
                'notifEventLabels' => $needsNotifications ? $this->databaseEventLabels() : [],
            ],
        ));
    }
}
