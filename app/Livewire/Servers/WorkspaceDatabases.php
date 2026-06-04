<?php

namespace App\Livewire\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\InstallDatabaseEngineJob;
use App\Jobs\ToggleDatabaseEngineActivationJob;
use App\Jobs\ToggleDatabaseEngineRemoteAccessJob;
use App\Jobs\ToggleDatabaseNetworkingJob;
use App\Jobs\UninstallDatabaseEngineJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\SurfacesBindingConsumers;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Livewire\Servers\Concerns\RunsServerConsoleActions;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAdminCredential;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseBackup;
use App\Models\ServerDatabaseCredentialShare;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseExtraUser;
use App\Notifications\ServerDatabaseCredentialsNotification;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Notifications\ServerDatabaseNotificationDispatcher;
use App\Services\Servers\DatabaseBackupDownloader;
use App\Services\Servers\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseDriftAnalyzer;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerDatabaseRemoteExec;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\DatabaseEngineAvailability;
use App\Support\Servers\DatabaseEngineInfo;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\DatabaseWorkspaceViewData;
use App\Services\Servers\PostgresExtensionManager;
use App\Support\Servers\PostgresExtensionCatalog;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class WorkspaceDatabases extends Component
{
    use ConfirmsActionWithModal;
    use DismissesServerConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RunsAllowlistedManageAction;
    use RunsServerConsoleActions;
    use SurfacesBindingConsumers;
    use WithFileUploads;

    #[Url(as: 'tab', except: 'databases', history: true)]
    public string $workspace_tab = 'databases';

    public string $new_db_name = '';

    public string $new_db_engine = 'mysql';

    public bool $engine_create_form_open = false;

    public string $new_db_username = '';

    public string $new_db_password = '';

    public string $new_db_user_mode = 'new';

    public string $new_db_existing_user_reference = '';

    public string $new_db_host = '127.0.0.1';

    public ?string $new_db_description = null;

    public ?string $new_mysql_charset = null;

    public ?string $new_mysql_collation = null;

    /** @var list<string> */
    public array $remote_mysql_databases = [];

    /** @var list<string> */
    public array $remote_postgres_databases = [];

    /** @var list<string> */
    public array $remote_mongodb_databases = [];

    /** @var list<string> */
    public array $remote_clickhouse_databases = [];

    /** @var list<string> */
    public array $postgres_installed_extensions = [];

    public ?string $credentials_modal_db_id = null;

    public ?string $connection_url_modal_db_id = null;

    /** @var array{name: string, engine: string, username: string, password: string, host: string, password_generated: bool, username_generated: bool}|null */
    public ?array $generated_database_credentials = null;

    public string $admin_mysql_root_username = 'root';

    public string $admin_mysql_root_password = '';

    public string $admin_postgres_superuser = 'postgres';

    public string $admin_postgres_password = '';

    public bool $admin_postgres_use_sudo = true;

    public string $admin_mongodb_username = 'admin';

    public string $admin_mongodb_password = '';

    public string $admin_clickhouse_username = 'default';

    public string $admin_clickhouse_password = '';

    public string $remote_access_engine = '';

    public string $remote_access_allowed_from = '0.0.0.0/0';

    /** Keyed by database ID — the CIDR input value for each row's networking form. */
    public array $db_networking_allowed_from = [];

    public string $extra_db_id = '';

    public string $extra_username = '';

    public string $extra_password = '';

    public string $extra_host = 'localhost';

    public ?string $share_target_db_id = null;

    public string $import_target_db_id = '';

    public int $share_expires_hours;

    public int $share_max_views;

    public ?string $share_link_modal_url = null;

    public ?string $share_link_modal_db_name = null;

    /** @var array<string, mixed>|null */
    public ?array $drift_snapshot = null;

    /** State for the unified Edit modal (engine-aware). */
    public ?string $editing_db_id = null;

    public string $editing_db_engine = '';

    public string $editing_db_name = '';

    public string $edit_description = '';

    public string $edit_mysql_charset = '';

    public string $edit_mysql_collation = '';

    public string $edit_sqlite_path = '';

    /** State for the SQLite SQL console modal. */
    public ?string $sqlite_console_db_id = null;

    public string $sqlite_console_sql = '';

    public string $sqlite_console_output = '';

    public ?int $sqlite_console_exit_code = null;

    protected bool $driftProbeErrorNotified = false;

    public $import_sql_file = null;

    public function boot(): void
    {
        $this->share_expires_hours = (int) config('server_database.credential_share_expires_hours', 72);
        $this->share_max_views = (int) config('server_database.credential_share_max_views', 3);
    }

    /**
     * Optional bind address Dply uses when reading live database state over SSH
     * (e.g. SHOW PROCESSLIST via the `mysql_processlist` allowlisted action).
     * Persisted to `$server->meta['manage_db_bind_host']`. Migrated here from
     * WorkspaceManage when /manage/data was retired.
     */
    public string $manage_db_bind_host = '';

    public ?int $manage_db_port = null;

    /** Write-only field — populated on save, cleared on render. */
    public string $manage_db_password = '';

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
     * Persist the optional MySQL connection hints into `$server->meta`. The
     * password is write-only — empty input leaves the stored value untouched
     * so reloading the page (which never re-shows the password) doesn't wipe it.
     * Mirrors the pre-retirement Manage→Data form.
     */
    public function saveManageDbHints(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change manage settings.'));

            return;
        }

        $this->validate([
            'manage_db_bind_host' => ['nullable', 'string', 'max:255'],
            'manage_db_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['manage_db_bind_host'] = $this->manage_db_bind_host !== '' ? $this->manage_db_bind_host : null;
        $meta['manage_db_port'] = $this->manage_db_port;

        if ($this->manage_db_password !== '') {
            $meta['manage_internal_db_password'] = $this->manage_db_password;
        }

        $this->server->update(['meta' => $meta]);
        $this->manage_db_password = '';
        $this->server->refresh();
        $this->toastSuccess(__('Connection hints saved.'));
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

    public function openEngineDatabaseCreate(string $engine): void
    {
        $this->authorize('update', $this->server);

        if (! in_array($engine, DatabaseWorkspaceEngines::ENGINE_TABS, true)) {
            return;
        }

        $capabilities = app(ServerDatabaseHostCapabilities::class)->forServer($this->server);
        if (! ($capabilities[$engine] ?? false)) {
            $this->toastError(__(':engine is not installed on this server.', ['engine' => DatabaseWorkspaceEngines::label($engine)]));

            return;
        }

        $this->workspace_tab = $engine;
        $this->engine_subtab = 'databases';
        $this->new_db_engine = $engine;
        $this->resetCreateDatabaseFormFields();
        $this->engine_create_form_open = true;
    }

    public function closeEngineDatabaseCreate(): void
    {
        $this->engine_create_form_open = false;
    }

    public function prepareSqliteCreate(): void
    {
        $this->openEngineDatabaseCreate('sqlite');
    }

    protected function resetCreateDatabaseFormFields(): void
    {
        $this->new_db_name = '';
        $this->new_db_user_mode = 'new';
        $this->new_db_existing_user_reference = '';
        $this->new_db_username = '';
        $this->new_db_password = '';
        $this->new_db_description = null;
        $this->new_mysql_charset = null;
        $this->new_mysql_collation = null;
    }

    public function setEngineSubtab(string $subtab): void
    {
        $this->engine_subtab = in_array($subtab, self::ENGINE_SUBTABS, true) ? $subtab : 'overview';
    }

    /**
     * Queue an install for the requested database engine. Mirrors the Caches workspace install
     * flow: a `ServerDatabaseEngine` row is created in PENDING; the queued job runs preflight,
     * apt-installs, parses the version, and flips status to RUNNING.
     *
     * Workspace tabs are engine families ('mysql', 'postgres'); the engine column on the row
     * holds the canonical short key the install scripts understand.
     */
    public function installDatabaseEngine(string $engine): void
    {
        $this->authorize('update', $this->server);

        if (! in_array($engine, DatabaseEngineInstallScripts::supportedEngines(), true)) {
            $this->toastError(__('Unsupported database engine.'));

            return;
        }

        // Coming-soon gate — MariaDB / MongoDB / ClickHouse are gated behind
        // database.{engine} flags until their install path is GA. Refuse before
        // queueing so a stale payload can't slip past the disabled UI.
        if (DatabaseEngineAvailability::isComingSoon($engine)) {
            $this->toastError(__(':engine isn\'t available yet — it\'s coming soon.', [
                'engine' => DatabaseEngineInfo::for($engine)['label'] ?? ucfirst($engine),
            ]));

            return;
        }

        $existing = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->first();

        if ($existing && $existing->status === ServerDatabaseEngine::STATUS_RUNNING) {
            $this->toastError(__(':engine is already installed.', ['engine' => $engine]));

            return;
        }

        $row = $existing ?? ServerDatabaseEngine::query()->create([
            'server_id' => $this->server->id,
            'engine' => $engine,
            'status' => ServerDatabaseEngine::STATUS_PENDING,
            'is_default' => ServerDatabaseEngine::query()->where('server_id', $this->server->id)->doesntExist(),
            'port' => ServerDatabaseEngine::defaultPortFor($engine),
        ]);

        // Re-run install only when the row is in a state that makes sense to retry from.
        if (! in_array($row->status, [
            ServerDatabaseEngine::STATUS_PENDING,
            ServerDatabaseEngine::STATUS_FAILED,
            ServerDatabaseEngine::STATUS_STOPPED,
        ], true)) {
            $this->toastError(__('Install is already in progress.'));

            return;
        }

        $this->workspace_tab = $engine;

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];
        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_install',
            __('Installing :engine on :host …', [
                'engine' => $engineLabel,
                'host' => $this->server->name,
            ]),
        );

        InstallDatabaseEngineJob::dispatch($row->id, auth()->id());
        $this->toastSuccess(__('Installing :engine — progress shows in the banner above.', ['engine' => $engineLabel]));
    }

    /**
     * Operator escape hatch when a database-engine install has stalled. Mirrors
     * the webserver-switch and cache-install Stop & revert patterns: marks the
     * pending/installing row FAILED with an "operator-aborted" reason, then
     * dispatches {@see UninstallDatabaseEngineJob} to apt-purge whatever the
     * install partially landed (idempotent — the uninstall script tolerates
     * "not actually installed" too).
     *
     * The install job's success-path update (`status = running`) is keyed by
     * row id; once it finishes its long SSH bash it'll find the row in FAILED
     * state and the uninstall will already be queued behind it on the
     * server_database.install_queue, so the two serialise rather than race.
     */
    public function stopAndRevertDatabaseEngineInstall(string $engine): void
    {
        $this->authorize('update', $this->server);

        $engine = strtolower(trim($engine));
        if (! in_array($engine, DatabaseEngineInstallScripts::supportedEngines(), true)) {
            $this->toastError(__('Unsupported database engine.'));

            return;
        }

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->first();

        if ($row === null) {
            $this->toastError(__('No :engine install to stop.', ['engine' => $engine]));

            return;
        }

        if (! in_array($row->status, [
            ServerDatabaseEngine::STATUS_PENDING,
            ServerDatabaseEngine::STATUS_INSTALLING,
        ], true)) {
            $this->toastError(__(':engine is not currently installing (status: :status).', [
                'engine' => $engine,
                'status' => $row->status,
            ]));

            return;
        }

        $row->update([
            'status' => ServerDatabaseEngine::STATUS_FAILED,
            'error_message' => __('Stopped by operator — reverting partial install.'),
        ]);

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];
        $this->workspace_tab = $engine;
        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_uninstall',
            __('Reverting :engine install on :host …', [
                'engine' => $engineLabel,
                'host' => $this->server->name,
            ]),
        );

        UninstallDatabaseEngineJob::dispatch($row->id, auth()->id());

        $this->toastSuccess(__('Stopping :engine install and reverting — progress shows in the banner above.', [
            'engine' => $engineLabel,
        ]));
    }

    /**
     * Queue an uninstall for the engine identified by tab name. Tracks the underlying row by
     * engine + server so memcached-style swaps don't accidentally drop the wrong row.
     */
    public function uninstallDatabaseEngine(string $engine): void
    {
        $this->authorize('update', $this->server);

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->first();

        if (! $row) {
            $this->toastError(__('No :engine engine to uninstall.', ['engine' => $engine]));

            return;
        }

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];
        $this->workspace_tab = $engine;
        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_uninstall',
            __('Uninstalling :engine on :host …', [
                'engine' => $engineLabel,
                'host' => $this->server->name,
            ]),
        );

        UninstallDatabaseEngineJob::dispatch($row->id, auth()->id());
        $this->toastSuccess(__('Uninstalling :engine — progress shows in the banner above.', ['engine' => $engineLabel]));
    }

    /**
     * Activate (start + enable at boot) or deactivate (stop + disable at boot) an
     * installed database engine. Dispatches {@see ToggleDatabaseEngineActivationJob}
     * which runs the systemctl change over SSH and flips the row to RUNNING/STOPPED.
     * The daemon stays installed — data and binaries are untouched.
     */
    public function setDatabaseEngineActivation(string $engine, bool $activate): void
    {
        $this->authorize('update', $this->server);

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->first();

        if (! $row) {
            $this->toastError(__('No :engine engine on this server.', ['engine' => $engine]));

            return;
        }

        // Only toggle between the two resting states — never interrupt an install,
        // uninstall, or a failed row (those have their own recovery actions).
        if (! in_array($row->status, [ServerDatabaseEngine::STATUS_RUNNING, ServerDatabaseEngine::STATUS_STOPPED], true)) {
            $this->toastError(__(':engine is busy — wait for the current operation to finish.', ['engine' => $engine]));

            return;
        }

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];
        $this->workspace_tab = $engine;

        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_activation',
            $activate
                ? __('Activating :engine on :host …', ['engine' => $engineLabel, 'host' => $this->server->name])
                : __('Deactivating :engine on :host …', ['engine' => $engineLabel, 'host' => $this->server->name]),
        );

        // Optimistically flip the pill so the UI reflects intent immediately; the
        // job confirms (or reverts via its failure path) once SSH completes.
        $row->update([
            'status' => $activate ? ServerDatabaseEngine::STATUS_RUNNING : ServerDatabaseEngine::STATUS_STOPPED,
        ]);

        ToggleDatabaseEngineActivationJob::dispatch($row->id, $activate, auth()->id());

        $this->toastSuccess(
            $activate
                ? __('Activating :engine — progress shows in the banner above.', ['engine' => $engineLabel])
                : __('Deactivating :engine — progress shows in the banner above.', ['engine' => $engineLabel])
        );
    }

    /**
     * Enable or disable remote access (public/CIDR-gated port exposure) for a
     * database engine. Dispatches {@see ToggleDatabaseEngineRemoteAccessJob} which
     * updates listen_addresses / pg_hba / bind-address over SSH, restarts the
     * service, and syncs the UFW-backed firewall rule.
     */
    public function toggleDatabaseEngineRemoteAccess(string $engine, bool $enable): void
    {
        $this->authorize('update', $this->server);

        if (! DatabaseEngineInstallScripts::supportsRemoteAccess($engine)) {
            $this->toastError(__('Remote access configuration is not supported for :engine.', ['engine' => $engine]));

            return;
        }

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('status', ServerDatabaseEngine::STATUS_RUNNING)
            ->first();

        if (! $row) {
            $this->toastError(__(':engine is not running on this server.', ['engine' => $engine]));

            return;
        }

        $allowedFrom = $enable ? trim($this->remote_access_allowed_from) : '';

        if ($enable) {
            // Require an explicit trusted source — never silently open the engine
            // port to the whole internet on a blank field.
            if ($allowedFrom === '') {
                $this->addError('remote_access_allowed_from', __('Enter the CIDR allowed to connect (e.g. 10.0.0.0/8 or your app server IP/32). Leave remote access off to keep the port closed.'));

                return;
            }

            // Basic CIDR sanity — must look like x.x.x.x/n or x::/n.
            if (! $this->isValidRemoteCidr($allowedFrom)) {
                $this->addError('remote_access_allowed_from', __('Enter a valid CIDR (e.g. 10.0.0.0/8, 203.0.113.5/32).'));

                return;
            }
        }

        $engineLabel = DatabaseEngineInfo::for($engine)['label'];

        $this->seedQueuedDatabaseEngineConsoleAction(
            $row,
            'db_engine_remote_access',
            $enable
                ? __('Enabling remote access for :engine on :host …', ['engine' => $engineLabel, 'host' => $this->server->name])
                : __('Disabling remote access for :engine on :host …', ['engine' => $engineLabel, 'host' => $this->server->name]),
        );

        // Optimistically update the row so the UI flips immediately.
        $row->update([
            'remote_access' => $enable,
            'allowed_from' => $enable ? $allowedFrom : null,
        ]);

        ToggleDatabaseEngineRemoteAccessJob::dispatch($row->id, $enable, $allowedFrom, auth()->id());

        $this->toastSuccess(
            $enable
                ? __('Enabling remote access for :engine — progress shows in the banner above.', ['engine' => $engineLabel])
                : __('Disabling remote access for :engine — progress shows in the banner above.', ['engine' => $engineLabel])
        );
    }

    /**
     * Enable or disable remote network access for a specific database.
     * Updates pg_hba (postgres) or bind-address + GRANT (mysql/mariadb) over SSH,
     * then syncs the engine-level UFW rule.
     */
    public function toggleDatabaseNetworking(string $databaseId, bool $enable): void
    {
        $this->authorize('update', $this->server);

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->find($databaseId);

        if (! $db) {
            $this->toastError(__('Database not found.'));

            return;
        }

        if (! DatabaseEngineInstallScripts::supportsRemoteAccess($db->engine)) {
            $this->toastError(__('Remote access is not supported for :engine.', ['engine' => $db->engine]));

            return;
        }

        $allowedFrom = $enable ? trim($this->db_networking_allowed_from[$databaseId] ?? '') : '';

        if ($enable) {
            // Require an explicit trusted source — never silently open the port
            // to the whole internet on a blank field.
            if ($allowedFrom === '') {
                $this->addError('db_networking_allowed_from.'.$databaseId, __('Enter the CIDR allowed to connect (e.g. 10.0.0.0/8 or your app server IP/32). Leave remote access off to keep the port closed.'));

                return;
            }
            if (! $this->isValidRemoteCidr($allowedFrom)) {
                $this->addError('db_networking_allowed_from.'.$databaseId, __('Enter a valid CIDR (e.g. 10.0.0.0/8, 203.0.113.5/32).'));

                return;
            }
        }

        // Optimistically update so the UI flips immediately.
        $db->update([
            'remote_access' => $enable,
            'allowed_from' => $enable ? $allowedFrom : null,
        ]);

        ToggleDatabaseNetworkingJob::dispatch($databaseId, $enable, $allowedFrom, auth()->id());

        $this->toastSuccess(
            $enable
                ? __('Enabling remote access for :name — progress shows in the banner above.', ['name' => $db->name])
                : __('Disabling remote access for :name — progress shows in the banner above.', ['name' => $db->name])
        );
    }

    private function isValidRemoteCidr(string $value): bool
    {
        if ($value === '' || $value === 'any') {
            return false;
        }

        $parts = array_filter(array_map('trim', explode(',', $value)));
        foreach ($parts as $part) {
            if (! str_contains($part, '/')) {
                return false;
            }
            [$ip, $prefix] = explode('/', $part, 2);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }
            if (! is_numeric($prefix) || (int) $prefix < 0 || (int) $prefix > 128) {
                return false;
            }
        }

        return true;
    }

    /**
     * Seed a queued ConsoleAction on the engine row before dispatch so the banner
     * appears immediately (mirrors webserver switch + site config apply).
     */
    protected function seedQueuedDatabaseEngineConsoleAction(
        ServerDatabaseEngine $row,
        string $kind,
        string $label,
    ): ConsoleAction {
        ConsoleAction::query()
            ->where('subject_type', $row->getMorphClass())
            ->where('subject_id', $row->getKey())
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $row->getMorphClass())
            ->where('subject_id', $row->getKey())
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        return ConsoleAction::query()->create([
            'subject_type' => $row->getMorphClass(),
            'subject_id' => $row->getKey(),
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => $label,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    public function refreshDatabaseCapabilities(ServerDatabaseHostCapabilities $capabilities): void
    {
        $this->authorize('update', $this->server);
        $capabilities->forget($this->server);

        // Seed ServerDatabaseEngine rows for any engines running on the server that
        // dply doesn't have a record for yet (e.g. installed during provisioning or
        // manually via SSH — the "provision seeding gap").
        $detected = $capabilities->probe($this->server);
        $seededEngines = [];
        foreach ($detected as $engine => $running) {
            if (! $running || $engine === 'sqlite') {
                continue;
            }
            $exists = ServerDatabaseEngine::query()
                ->where('server_id', $this->server->id)
                ->where('engine', $engine)
                ->exists();
            if (! $exists) {
                ServerDatabaseEngine::query()->create([
                    'server_id' => $this->server->id,
                    'engine' => $engine,
                    'status' => ServerDatabaseEngine::STATUS_RUNNING,
                    'is_default' => ServerDatabaseEngine::query()->where('server_id', $this->server->id)->doesntExist(),
                    'port' => ServerDatabaseEngine::defaultPortFor($engine),
                ]);
                $seededEngines[] = $engine;
            }
        }

        if ($seededEngines !== []) {
            $labels = implode(', ', array_map(fn ($e) => ucfirst($e), $seededEngines));
            $this->toastSuccess(__('Rechecked engines — adopted :engines as already installed.', ['engines' => $labels]));
        } else {
            $this->toastSuccess(__('Rechecked the server for database engines.'));
        }
    }

    public function generateNewDbPassword(): void
    {
        $this->new_db_password = ServerDatabase::generateConnectionSafePassword();
    }

    public function generateAdminMysqlRootPassword(): void
    {
        $this->admin_mysql_root_password = ServerDatabase::generateConnectionSafePassword();
    }

    public function generateAdminPostgresPassword(): void
    {
        $this->admin_postgres_password = ServerDatabase::generateConnectionSafePassword();
    }

    public function updatedNewDbEngine(string $value): void
    {
        if (! DatabaseWorkspaceEngines::isMysqlFamily($value)) {
            $this->new_db_user_mode = 'new';
            $this->new_db_existing_user_reference = '';
        }
    }

    /**
     * Auto-format the database name as the operator types so they never see
     * a "format is invalid" error for trivial things like spaces, dashes, or
     * casing. The on-submit regex still gates the final value as a safety net.
     *
     * Rules: lowercase; spaces, dashes, dots → underscore; strip everything
     * else outside [a-z0-9_]; collapse runs of underscores; trim leading and
     * trailing underscores. Length cap at 64 matches the validation rule.
     *
     * Trade-off: the trailing-underscore trim means a user pausing mid-name
     * at "foo_" sees the underscore disappear (debounced after 250ms) and
     * has to retype it as "foo_bar". That's strictly better than ending up
     * with "foo_.db" as the file path on creation.
     */
    public function updatedNewDbName(string $value): void
    {
        $sanitized = strtolower($value);
        $sanitized = preg_replace('/[\s.\-]+/', '_', $sanitized) ?? '';
        $sanitized = preg_replace('/[^a-z0-9_]/', '', $sanitized) ?? '';
        $sanitized = preg_replace('/_+/', '_', $sanitized) ?? '';
        $sanitized = trim($sanitized, '_');
        $sanitized = substr($sanitized, 0, 64);

        if ($sanitized !== $value) {
            $this->new_db_name = $sanitized;
        }
    }

    public function openCredentialsModal(string $databaseId): void
    {
        $this->authorize('update', $this->server);
        $exists = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($databaseId)
            ->exists();
        $this->credentials_modal_db_id = $exists ? $databaseId : null;
    }

    public function closeCredentialsModal(): void
    {
        $this->credentials_modal_db_id = null;
    }

    public function openConnectionUrlModal(string $databaseId): void
    {
        $this->authorize('update', $this->server);
        $exists = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($databaseId)
            ->exists();
        $this->connection_url_modal_db_id = $exists ? $databaseId : null;
    }

    public function closeConnectionUrlModal(): void
    {
        $this->connection_url_modal_db_id = null;
    }

    public function openEditDatabaseModal(string $databaseId): void
    {
        $this->authorize('update', $this->server);

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($databaseId)
            ->first();
        if (! $db) {
            return;
        }

        $this->editing_db_id = $db->id;
        $this->editing_db_engine = (string) $db->engine;
        $this->editing_db_name = (string) $db->name;
        $this->edit_description = (string) ($db->description ?? '');
        $this->edit_mysql_charset = (string) ($db->mysql_charset ?? '');
        $this->edit_mysql_collation = (string) ($db->mysql_collation ?? '');
        $this->edit_sqlite_path = (string) ($db->host ?? '');

        // Per the planner notes: cross-engine edits use the dispatch
        // open-modal pattern (same wiring as personal-ssh-key-modal),
        // not the ConfirmsActionWithModal trait which is reserved for
        // single-confirm destructive actions like drop.
        $this->dispatch('open-modal', 'edit-database-modal');
    }

    public function closeEditDatabaseModal(): void
    {
        $this->dispatch('close-modal', 'edit-database-modal');

        $this->editing_db_id = null;
        $this->editing_db_engine = '';
        $this->editing_db_name = '';
        $this->edit_description = '';
        $this->edit_mysql_charset = '';
        $this->edit_mysql_collation = '';
        $this->edit_sqlite_path = '';
    }

    public function saveDatabaseEdit(
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);

        if (! $this->editing_db_id) {
            return;
        }

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->editing_db_id)
            ->firstOrFail();

        $rules = [
            'edit_description' => 'nullable|string|max:2000',
        ];
        if (DatabaseWorkspaceEngines::isMysqlFamily($db->engine)) {
            $rules['edit_mysql_charset'] = 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]*$/';
            $rules['edit_mysql_collation'] = 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]*$/';
        }
        if ($db->engine === 'sqlite') {
            $rules['edit_sqlite_path'] = 'required|string|max:512';
        }
        $this->validate($rules);

        $diff = [];

        if ((string) ($db->description ?? '') !== $this->edit_description) {
            $diff['description'] = ['from' => $db->description, 'to' => $this->edit_description ?: null];
            $db->description = $this->edit_description ?: null;
        }

        if (DatabaseWorkspaceEngines::isMysqlFamily($db->engine)) {
            $newCharset = $this->edit_mysql_charset ?: null;
            $newCollation = $this->edit_mysql_collation ?: null;
            if ($db->mysql_charset !== $newCharset) {
                $diff['mysql_charset'] = ['from' => $db->mysql_charset, 'to' => $newCharset];
                $db->mysql_charset = $newCharset;
            }
            if ($db->mysql_collation !== $newCollation) {
                $diff['mysql_collation'] = ['from' => $db->mysql_collation, 'to' => $newCollation];
                $db->mysql_collation = $newCollation;
            }
        }

        if ($db->engine === 'sqlite' && trim($this->edit_sqlite_path) !== (string) $db->host) {
            // Run the host-side mv BEFORE updating the host column so a
            // failed move leaves the row pointing at a file that still
            // exists. The provisioner re-validates both paths through
            // safeSqlitePath() so a tampered field can't escape the jail.
            try {
                $provisioner->relocateSqliteFile($db, trim($this->edit_sqlite_path));
            } catch (\Throwable $e) {
                $this->toastError(__('Could not move SQLite file: :msg', ['msg' => $e->getMessage()]));

                return;
            }

            $diff['host'] = ['from' => $db->host, 'to' => trim($this->edit_sqlite_path)];
            $db->host = trim($this->edit_sqlite_path);
        }

        if ($diff === []) {
            $this->toastSuccess(__('Nothing to update.'));
            $this->closeEditDatabaseModal();

            return;
        }

        $db->save();

        $auditLogger->record(
            $this->server,
            ServerDatabaseAuditEvent::EVENT_DATABASE_UPDATED,
            ['server_database_id' => $db->id, 'engine' => $db->engine, 'diff' => $diff],
            auth()->user(),
        );

        $this->toastSuccess(__('Database updated.'));
        $this->closeEditDatabaseModal();
    }

    public function openSqliteConsoleModal(string $databaseId): void
    {
        $this->authorize('update', $this->server);

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($databaseId)
            ->first();
        if (! $db || $db->engine !== 'sqlite') {
            return;
        }

        $this->sqlite_console_db_id = $db->id;
        $this->sqlite_console_sql = '';
        $this->sqlite_console_output = '';
        $this->sqlite_console_exit_code = null;
        $this->dispatch('open-modal', 'sqlite-sql-console-modal');
    }

    public function closeSqliteConsoleModal(): void
    {
        $this->dispatch('close-modal', 'sqlite-sql-console-modal');
        $this->sqlite_console_db_id = null;
        $this->sqlite_console_sql = '';
        $this->sqlite_console_output = '';
        $this->sqlite_console_exit_code = null;
    }

    public function runSqliteSql(
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);

        if (! $this->sqlite_console_db_id) {
            return;
        }

        $this->validate([
            'sqlite_console_sql' => 'required|string|min:1|max:'.((int) config('server_database.import_max_bytes', 10485760)),
        ]);

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->sqlite_console_db_id)
            ->firstOrFail();

        if ($db->engine !== 'sqlite') {
            $this->toastError(__('SQL console is only available for SQLite databases.'));

            return;
        }

        try {
            $output = $provisioner->executeSqliteSql($db, $this->sqlite_console_sql);
            $this->sqlite_console_output = $output;
            // sqlite3 exits 0 on success, non-zero on parse error or
            // constraint violation; the provisioner returns the trimmed
            // mixed stdout/stderr stream and we re-inspect via a quick
            // shell call. Keeping it simple: we only mark exit_code
            // non-zero when the output looks like a sqlite3 error line.
            $this->sqlite_console_exit_code = str_contains(strtolower($output), 'error') ? 1 : 0;
        } catch (\Throwable $e) {
            $this->sqlite_console_output = $e->getMessage();
            $this->sqlite_console_exit_code = 1;

            return;
        }

        $auditLogger->record(
            $this->server,
            ServerDatabaseAuditEvent::EVENT_IMPORT_RAN,
            ['server_database_id' => $db->id, 'engine' => 'sqlite', 'sql_length' => strlen($this->sqlite_console_sql)],
            auth()->user(),
        );
    }

    public function dismissGeneratedDatabaseCredentials(): void
    {
        $this->generated_database_credentials = null;
    }

    public function hideGeneratedDatabasePassword(): void
    {
        if ($this->generated_database_credentials === null) {
            return;
        }

        unset($this->generated_database_credentials['password']);
        $this->generated_database_credentials['password_hidden'] = true;
    }

    public function closeShareLinkModal(): void
    {
        $this->share_link_modal_url = null;
        $this->share_link_modal_db_name = null;
    }

    public function saveAdminCredentials(string $engine, ServerDatabaseAuditLogger $auditLogger): void
    {
        $this->authorize('update', $this->server);
        $engine = strtolower(trim($engine));

        $cred = ServerDatabaseAdminCredential::query()->firstOrNew(['server_id' => $this->server->id]);

        if (DatabaseWorkspaceEngines::isMysqlFamily($engine)) {
            $this->validate([
                'admin_mysql_root_username' => 'required|string|max:64',
                'admin_mysql_root_password' => 'nullable|string|max:500',
            ]);
            $cred->mysql_root_username = $this->admin_mysql_root_username;
            if ($this->admin_mysql_root_password !== '') {
                $cred->mysql_root_password = $this->admin_mysql_root_password;
            }
            $this->admin_mysql_root_password = '';
        } elseif ($engine === 'postgres') {
            $this->validate([
                'admin_postgres_superuser' => 'required|string|max:64',
                'admin_postgres_use_sudo' => 'boolean',
                'admin_postgres_password' => 'nullable|string|max:500',
            ]);
            $cred->postgres_superuser = $this->admin_postgres_superuser;
            $cred->postgres_use_sudo = $this->admin_postgres_use_sudo;
            if ($this->admin_postgres_password !== '') {
                $cred->postgres_password = $this->admin_postgres_password;
            }
            $this->admin_postgres_password = '';
        } elseif ($engine === 'mongodb') {
            $this->validate([
                'admin_mongodb_username' => 'required|string|max:64',
                'admin_mongodb_password' => 'nullable|string|max:500',
            ]);
            $cred->mongodb_admin_username = $this->admin_mongodb_username;
            if ($this->admin_mongodb_password !== '') {
                $cred->mongodb_admin_password = $this->admin_mongodb_password;
            }
            $this->admin_mongodb_password = '';
        } elseif ($engine === 'clickhouse') {
            $this->validate([
                'admin_clickhouse_username' => 'required|string|max:64',
                'admin_clickhouse_password' => 'nullable|string|max:500',
            ]);
            $cred->clickhouse_admin_username = $this->admin_clickhouse_username;
            if ($this->admin_clickhouse_password !== '') {
                $cred->clickhouse_admin_password = $this->admin_clickhouse_password;
            }
            $this->admin_clickhouse_password = '';
        } else {
            return;
        }

        $cred->save();

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_ADMIN_CREDENTIALS_SAVED, ['engine' => $engine], auth()->user());
        $this->toastSuccess(__('Saved :engine admin credentials.', ['engine' => DatabaseWorkspaceEngines::label($engine)]));
    }

    public function generateAdminMongodbPassword(): void
    {
        $this->admin_mongodb_password = ServerDatabase::generateConnectionSafePassword();
    }

    public function generateAdminClickhousePassword(): void
    {
        $this->admin_clickhouse_password = ServerDatabase::generateConnectionSafePassword();
    }

    public function clearStoredMongodbPassword(): void
    {
        $this->authorize('update', $this->server);
        $cred = ServerDatabaseAdminCredential::query()->where('server_id', $this->server->id)->first();
        if ($cred) {
            $cred->mongodb_admin_password = null;
            $cred->save();
        }
        $this->toastSuccess(__('Cleared stored MongoDB admin password.'));
    }

    public function clearStoredClickhousePassword(): void
    {
        $this->authorize('update', $this->server);
        $cred = ServerDatabaseAdminCredential::query()->where('server_id', $this->server->id)->first();
        if ($cred) {
            $cred->clickhouse_admin_password = null;
            $cred->save();
        }
        $this->toastSuccess(__('Cleared stored ClickHouse admin password.'));
    }

    public function loadPostgresExtensions(
        PostgresExtensionManager $manager,
        ServerDatabaseHostCapabilities $capabilitiesService,
    ): void {
        if ($this->workspace_tab !== 'postgres') {
            return;
        }

        $caps = $capabilitiesService->forServer($this->server);
        if (! ($caps['postgres'] ?? false)) {
            return;
        }

        try {
            $this->postgres_installed_extensions = $manager->listInstalled($this->server);
        } catch (\Throwable) {
            $this->postgres_installed_extensions = [];
        }
    }

    public function installPostgresExtension(
        string $key,
        PostgresExtensionManager $manager,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        if (! in_array($key, PostgresExtensionCatalog::KEYS, true)) {
            $this->toastError(__('Unknown PostgreSQL extension.'));

            return;
        }

        try {
            $out = $manager->install($this->server, $key);
            $this->postgres_installed_extensions = $manager->listInstalled($this->server);
            $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_IMPORT_RAN, [
                'postgres_extension' => $key,
            ], auth()->user());
            $this->toastSuccess(__('Extension installed.').' '.Str::limit($out, 300));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function clearStoredMysqlRootPassword(): void
    {
        $this->authorize('update', $this->server);
        $cred = ServerDatabaseAdminCredential::query()->where('server_id', $this->server->id)->first();
        if ($cred) {
            $cred->mysql_root_password = null;
            $cred->save();
        }
        $this->toastSuccess(__('Cleared stored MySQL root password.'));
    }

    public function clearStoredPostgresPassword(): void
    {
        $this->authorize('update', $this->server);
        $cred = ServerDatabaseAdminCredential::query()->where('server_id', $this->server->id)->first();
        if ($cred) {
            $cred->postgres_password = null;
            $cred->save();
        }
        $this->toastSuccess(__('Cleared stored PostgreSQL password.'));
    }

    public function runDriftAnalysis(
        ServerDatabaseDriftAnalyzer $driftAnalyzer,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        $this->drift_snapshot = $driftAnalyzer->analyze($this->server);
        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DRIFT_CHECK, [], auth()->user());
        $this->toastSuccess(__('Drift analysis updated.'));
    }

    public function addExtraMysqlUser(
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        $this->validate([
            'extra_db_id' => 'required|ulid|exists:server_databases,id',
            'extra_username' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/',
            'extra_password' => 'required|string|max:200',
            'extra_host' => 'required|string|max:255',
        ]);

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->whereKey($this->extra_db_id)->firstOrFail();

        // Postgres roles are global (no @host concept); store
        // 'localhost' as a placeholder so the column constraint stays
        // satisfied without misleading the operator. MySQL/MariaDB
        // honours the host the user typed.
        $extraHost = $db->engine === 'postgres' ? 'localhost' : $this->extra_host;

        $extra = ServerDatabaseExtraUser::query()->create([
            'server_database_id' => $db->id,
            'username' => $this->extra_username,
            'password' => $this->extra_password,
            'host' => $extraHost,
        ]);

        try {
            $provisioner->createExtraDatabaseUser($db, $extra);
        } catch (\Throwable $e) {
            // Roll back the local row if the remote call exploded so
            // the dashboard doesn't show a user that isn't on the host.
            $extra->delete();
            $this->toastError(__('Could not create user on the server: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_EXTRA_USER_CREATED, [
            'server_database_id' => $db->id,
            'username' => $extra->username,
        ], auth()->user());

        $this->extra_username = '';
        $this->extra_password = '';
        $this->toastSuccess(__('Extra user created on the server.'));
    }

    public function removeExtraUser(
        string $extraId,
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);

        $row = ServerDatabaseExtraUser::query()
            ->whereKey($extraId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->with(['serverDatabase.server'])
            ->firstOrFail();

        $db = $row->serverDatabase;

        try {
            $provisioner->dropExtraDatabaseUser($db, $row);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not drop user on the server: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $dbId = $row->server_database_id;
        $user = $row->username;
        $row->delete();

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_EXTRA_USER_REMOVED, [
            'server_database_id' => $dbId,
            'username' => $user,
            'dropped_remote' => true,
        ], auth()->user());

        $this->toastSuccess(__('Dropped the MySQL user on the server and removed it from Dply.'));
    }

    public function createCredentialShare(ServerDatabaseAuditLogger $auditLogger): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org && ! $org->allowsDatabaseCredentialShares()) {
            $this->addError('share_target_db_id', __('This organization has disabled public credential share links.'));

            return;
        }

        $this->validate([
            'share_target_db_id' => 'required|ulid|exists:server_databases,id',
            'share_expires_hours' => 'required|integer|min:1|max:720',
            'share_max_views' => 'required|integer|min:1|max:50',
        ]);

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->whereKey($this->share_target_db_id)->firstOrFail();
        $token = Str::random(48);
        ServerDatabaseCredentialShare::query()->create([
            'server_database_id' => $db->id,
            'user_id' => auth()->id(),
            'token' => $token,
            'expires_at' => now()->addHours($this->share_expires_hours),
            'views_remaining' => $this->share_max_views,
            'max_views' => $this->share_max_views,
        ]);

        $url = route('database-credential-shares.show', ['token' => $token]);
        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_CREDENTIAL_SHARE_CREATED, [
            'server_database_id' => $db->id,
        ], auth()->user());

        $this->share_link_modal_url = $url;
        $this->share_link_modal_db_name = $db->name;
        $this->toastSuccess(__('Share link created.'));
    }

    public function queueExport(
        string $databaseId,
        ServerDatabaseAuditLogger $auditLogger,
        DatabaseBackupExporter $exporter,
    ): void {
        $this->authorize('update', $this->server);
        $db = ServerDatabase::query()->where('server_id', $this->server->id)->whereKey($databaseId)->firstOrFail();
        $backup = ServerDatabaseBackup::query()->create([
            'server_database_id' => $db->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);
        $exporter->prepareBackupRow($backup, $this->server);
        dispatch(new ExportServerDatabaseBackupJob($backup->id));
        $this->toastSuccess(__('Export queued. Refresh this page in a few moments and download from the backup list.'));
    }

    public function downloadBackup(string $backupId, DatabaseBackupDownloader $downloader): StreamedResponse|Response|null
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

    public function deleteDatabaseBackup(string $backupId, DatabaseBackupExporter $exporter): void
    {
        $this->authorize('update', $this->server);

        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->first();

        if ($backup === null) {
            return;
        }

        $exporter->deleteArtifact($backup);

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

        $this->toastSuccess(__('Backup deleted.'));
    }

    public function importSql(
        ServerDatabaseRemoteExec $remoteExec,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        $maxBytes = $this->server->organization?->databaseImportMaxBytes()
            ?? (int) config('server_database.import_max_bytes', 10485760);
        $maxKb = max(1, (int) ceil($maxBytes / 1024));

        $this->validate([
            'import_target_db_id' => 'required|ulid|exists:server_databases,id',
            'import_sql_file' => ['required', 'file', 'mimes:txt,sql', 'max:'.$maxKb],
        ]);

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->whereKey($this->import_target_db_id)->firstOrFail();
        $contents = file_get_contents($this->import_sql_file->getRealPath());
        if (! is_string($contents)) {
            $this->toastError(__('Could not read the uploaded file.'));

            return;
        }

        try {
            if ($db->engine === 'postgres') {
                $remoteExec->postgresImportFromString($db->server, $db->name, $db->username, $db->password, $contents, 600, $maxBytes);
            } else {
                $remoteExec->mysqlImportFromString($db->server, $db->name, $db->username, $db->password, $contents, 600, $maxBytes);
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_IMPORT_RAN, [
            'server_database_id' => $db->id,
            'bytes' => strlen($contents),
        ], auth()->user());
        $this->import_sql_file = null;
        $this->toastSuccess(__('Import finished.'));
    }

    public function synchronizeDatabases(
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);

        try {
            $this->remote_mysql_databases = $provisioner->listMysqlDatabaseNames($this->server);
        } catch (\Throwable) {
            $this->remote_mysql_databases = [];
        }
        try {
            $this->remote_postgres_databases = $provisioner->listPostgresDatabaseNames($this->server);
        } catch (\Throwable) {
            $this->remote_postgres_databases = [];
        }
        try {
            $this->remote_mongodb_databases = $provisioner->listMongodbDatabaseNames($this->server);
        } catch (\Throwable) {
            $this->remote_mongodb_databases = [];
        }
        try {
            $this->remote_clickhouse_databases = $provisioner->listClickhouseDatabaseNames($this->server);
        } catch (\Throwable) {
            $this->remote_clickhouse_databases = [];
        }

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_SYNC_RAN, [
            'mysql_count' => count($this->remote_mysql_databases),
            'postgres_count' => count($this->remote_postgres_databases),
        ], auth()->user());
        $this->toastSuccess(__('Queried the server for database names. Compare the lists below and add or import anything missing in Dply.'));
    }

    public function prefillDatabaseFromDiscovery(string $name, string $engine): void
    {
        $this->authorize('update', $this->server);
        if ($engine === 'postgres') {
            $engine = 'postgres';
        } elseif (in_array($engine, ['mariadb', 'mongodb', 'clickhouse'], true)) {
            // keep as passed
        } else {
            $capabilities = app(ServerDatabaseHostCapabilities::class)->forServer($this->server);
            if (($capabilities['mariadb'] ?? false) && ! ($capabilities['mysql'] ?? false)) {
                $engine = 'mariadb';
            } else {
                $engine = 'mysql';
            }
        }
        $this->workspace_tab = 'advanced';
        $this->new_db_name = $name;
        $this->new_db_engine = $engine;
        $this->new_db_user_mode = 'new';
        $this->new_db_existing_user_reference = '';
        $this->new_db_username = '';
        $this->new_db_password = '';
    }

    public function createDatabase(
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseAuditLogger $auditLogger,
        ServerDatabaseNotificationDispatcher $notificationDispatcher,
        ServerDatabaseHostCapabilities $capabilitiesService,
    ): void {
        $this->authorize('update', $this->server);
        $this->new_db_username = trim($this->new_db_username);

        $capabilities = $capabilitiesService->forServer($this->server);
        if (! ($capabilities[$this->new_db_engine] ?? false)) {
            $this->addError('new_db_engine', __(':engine is not installed on this server.', ['engine' => DatabaseWorkspaceEngines::label($this->new_db_engine)]));

            return;
        }

        $rules = [
            'new_db_name' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('server_databases', 'name')->where('server_id', $this->server->id),
            ],
            'new_db_engine' => 'required|in:'.implode(',', DatabaseWorkspaceEngines::ENGINE_TABS),
            'new_db_host' => 'required|string|max:512',
            'new_db_description' => 'nullable|string|max:2000',
            'new_mysql_charset' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]*$/',
            'new_mysql_collation' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]*$/',
            'new_db_user_mode' => 'required|in:new,existing',
            'new_db_existing_user_reference' => 'nullable|string|max:100',
        ];
        if ($this->new_db_engine === 'sqlite') {
            // SQLite has no roles or auth — skip user/password rules
            // entirely. The "host" field is repurposed to carry the
            // absolute file path on the server (validated by the
            // provisioner against server_database.sqlite_root).
            $rules['new_db_username'] = 'nullable';
            $rules['new_db_password'] = 'nullable';
        } elseif ($this->new_db_user_mode === 'existing' && DatabaseWorkspaceEngines::isMysqlFamily($this->new_db_engine)) {
            $rules['new_db_existing_user_reference'] = 'required|string|max:100';
            $rules['new_db_username'] = 'nullable';
            $rules['new_db_password'] = 'nullable';
        } elseif ($this->new_db_username !== '') {
            $rules['new_db_username'] = 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/';
            $rules['new_db_password'] = $this->new_db_password !== null && $this->new_db_password !== ''
                ? 'required|string|max:200'
                : 'nullable';
        } else {
            $rules['new_db_username'] = 'nullable';
            $rules['new_db_password'] = $this->new_db_password !== null && $this->new_db_password !== ''
                ? 'required|string|max:200'
                : 'nullable';
        }
        $this->validate($rules, [
            'new_db_name.unique' => __('A database named :name is already tracked on this server.', ['name' => $this->new_db_name]),
        ], [
            'new_db_name' => __('Name'),
        ]);

        // Force 'new' for non-MySQL engines so a stale form value
        // (operator switched from MySQL → SQLite without re-rendering)
        // can't trip the existing-user branch below.
        if (! DatabaseWorkspaceEngines::isMysqlFamily($this->new_db_engine)) {
            $this->new_db_user_mode = 'new';
        }

        $existingMysqlUser = null;
        if ($this->new_db_user_mode === 'existing') {
            if (! DatabaseWorkspaceEngines::isMysqlFamily($this->new_db_engine)) {
                $this->addError('new_db_user_mode', __('Existing user selection is currently supported for MySQL/MariaDB only.'));

                return;
            }

            $existingMysqlUser = $this->resolveExistingMysqlUser();
            if ($existingMysqlUser === null) {
                $this->addError('new_db_existing_user_reference', __('Choose an existing MySQL user to grant access to this database.'));

                return;
            }
        }

        $isSqlite = $this->new_db_engine === 'sqlite';

        $username = $existingMysqlUser['username'] ?? $this->new_db_username;
        $usernameGenerated = false;
        if (! $isSqlite && $username === '') {
            $base = Str::slug($this->new_db_name, '_');
            if ($base === '') {
                $base = 'db';
            }
            $username = Str::limit($base, 28, '').'_'.Str::lower(Str::random(4));
            $usernameGenerated = true;
        }

        $password = $existingMysqlUser['password'] ?? $this->new_db_password;
        $passwordGenerated = false;
        if (! $isSqlite && ($password === null || $password === '')) {
            $password = ServerDatabase::generateConnectionSafePassword();
            $passwordGenerated = true;
        }

        // SQLite always lands at the canonical layout — the create form
        // doesn't collect the path anymore. Existing rows with custom
        // paths keep theirs; only new creates pass through here.
        if ($isSqlite) {
            $root = rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/');
            $this->new_db_host = $root.'/'.$this->server->id.'/'.$this->new_db_name.'.db';
            $username = $username ?: '';
            $password = $password ?: '';
        }

        try {
            $db = ServerDatabase::query()->create([
                'server_id' => $this->server->id,
                'name' => $this->new_db_name,
                'engine' => $this->new_db_engine,
                'username' => $username,
                'password' => $password,
                'host' => $this->new_db_host,
                'description' => $this->new_db_description,
                'mysql_charset' => $this->new_mysql_charset ?: null,
                'mysql_collation' => $this->new_mysql_collation ?: null,
            ]);

            // Subject for the per-engine banner. SQLite has no engine row, so we fall
            // back to the Server itself — the banner will appear on the SQLite subtab
            // (filtered by db_create_sqlite kind) rather than on a non-existent row.
            $bannerSubject = ServerDatabaseEngine::query()
                ->where('server_id', $this->server->id)
                ->where('engine', $db->engine)
                ->first()
                ?? $this->server;

            $out = $this->runConsoleAction(
                $bannerSubject,
                'db_create',
                __('Create :engine database :name on :host', [
                    'engine' => $db->engine, 'name' => $db->name, 'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($provisioner, $db, $existingMysqlUser, $auditLogger): string {
                    $emit->step('db', sprintf('CREATE %s DATABASE %s', strtoupper($db->engine), $db->name));
                    $out = $existingMysqlUser
                        ? $provisioner->createMysqlDatabaseForExistingUser($db, $existingMysqlUser['grant_host'])
                        : $provisioner->createOnServer($db);
                    foreach (preg_split("/\r?\n/", (string) $out) ?: [] as $line) {
                        if ($line !== '') {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'db');
                        }
                    }
                    $emit->success('db', sprintf('Database %s ready.', $db->name));

                    $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DATABASE_CREATED, [
                        'server_database_id' => $db->id,
                        'engine' => $db->engine,
                        'name' => $db->name,
                        'used_existing_user' => $existingMysqlUser !== null,
                    ], auth()->user());

                    return (string) $out;
                },
            );

            $notificationDispatcher->notifyIfSubscribed($this->server, 'created', $db, auth()->user());
            $credentialsEmailed = $this->maybeEmailCreatedDatabaseCredentials($db, $isSqlite ? null : (string) $password);
            $this->toastSuccess(__('Database provisioned on the server.').' '.Str::limit($out, 500));
            $this->generated_database_credentials = [
                'name' => $db->name,
                'engine' => $db->engine,
                'username' => $db->username,
                'password' => $isSqlite ? null : (string) $password,
                'host' => $db->host,
                'port' => $db->defaultPort(),
                'server_public_ip' => $this->server->ip_address,
                'server_private_ip' => $this->server->private_ip_address,
                'remote_access' => (bool) $db->remote_access,
                'allowed_from' => $db->allowed_from,
                'username_generated' => $usernameGenerated,
                'password_generated' => $passwordGenerated,
                'credentials_emailed' => $credentialsEmailed,
                'password_hidden' => false,
            ];
            $this->engine_create_form_open = false;
            $this->engine_subtab = 'databases';
            $this->resetCreateDatabaseFormFields();
            $this->dispatch('open-modal', 'database-credentials-modal');
        } catch (UniqueConstraintViolationException) {
            $this->addError('new_db_name', __('A database named :name is already tracked on this server.', ['name' => $this->new_db_name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function deleteDatabase(
        string $id,
        ServerDatabaseAuditLogger $auditLogger,
        ServerDatabaseNotificationDispatcher $notificationDispatcher,
    ): void {
        $this->authorize('update', $this->server);
        $db = ServerDatabase::query()->where('server_id', $this->server->id)->findOrFail($id);
        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DATABASE_REMOVED_DPLY, [
            'server_database_id' => $db->id,
            'name' => $db->name,
        ], auth()->user());
        $notificationDispatcher->notifyIfSubscribed($this->server, 'removed', $db, auth()->user(), false);
        $db->delete();
        $this->toastSuccess(__('Removed this database from Dply. The database was not dropped on the server.'));
        if ($this->credentials_modal_db_id === $id) {
            $this->credentials_modal_db_id = null;
        }
    }

    public function dropDatabaseOnServer(
        string $id,
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseAuditLogger $auditLogger,
        ServerDatabaseNotificationDispatcher $notificationDispatcher,
    ): void {
        $this->authorize('update', $this->server);

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->findOrFail($id);

        try {
            // Subject the banner on the engine row so it surfaces on the engine's subtab.
            // SQLite has no engine row; fall back to the Server for that case so the banner
            // still renders somewhere reachable.
            $bannerSubject = ServerDatabaseEngine::query()
                ->where('server_id', $this->server->id)
                ->where('engine', $db->engine)
                ->first()
                ?? $this->server;

            $out = $this->runConsoleAction(
                $bannerSubject,
                'db_drop',
                __('Drop :engine database :name on :host', [
                    'engine' => $db->engine, 'name' => $db->name, 'host' => $this->server->name,
                ]),
                function (ConsoleEmitter $emit) use ($provisioner, $db, $auditLogger): string {
                    $emit->step('db', sprintf('DROP %s DATABASE %s', strtoupper($db->engine), $db->name));
                    $out = $provisioner->dropFromServer($db);
                    foreach (preg_split("/\r?\n/", (string) $out) ?: [] as $line) {
                        if ($line !== '') {
                            $emit($line, ConsoleAction::LEVEL_INFO, 'db');
                        }
                    }
                    $emit->success('db', sprintf('Database %s dropped on server.', $db->name));

                    $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DATABASE_DROPPED_REMOTE, [
                        'server_database_id' => $db->id,
                        'name' => $db->name,
                    ], auth()->user());

                    return (string) $out;
                },
            );

            $notificationDispatcher->notifyIfSubscribed($this->server, 'removed', $db, auth()->user(), true);
            $db->delete();
            $this->toastSuccess(__('Database and user were dropped on the server and the entry was removed from Dply.').' '.Str::limit($out, 400));
            if ($this->credentials_modal_db_id === $id) {
                $this->credentials_modal_db_id = null;
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function render(
        ServerDatabaseDriftAnalyzer $driftAnalyzer,
        ServerDatabaseHostCapabilities $capabilitiesService,
    ): View {
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

        $capabilities = DatabaseWorkspaceEngines::defaultCapabilities();
        if (! $needsNotifications) {
            try {
                $capabilities = $capabilitiesService->forServer($this->server);
            } catch (\Throwable) {
                // Probe failures (SSH timeout, key issues) leave engine badges off.
                // The user can still create databases from Basics — provisioner errors surface there.
            }
        }

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

        if ($needsEngine && in_array($activeEngine, DatabaseWorkspaceEngines::MANAGEABLE, true) && $this->drift_snapshot === null) {
            try {
                $this->drift_snapshot = $driftAnalyzer->analyze($this->server);
                $this->driftProbeErrorNotified = false;
            } catch (\Throwable $e) {
                $message = $this->friendlyDatabaseWorkspaceError(
                    $e,
                    __('Dply could not connect to the server to compare database drift.')
                );
                if (! $this->driftProbeErrorNotified) {
                    $this->toastError($message);
                    $this->driftProbeErrorNotified = true;
                }
            }
        }

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
                'credentialsModalDatabase' => $credentialsModalDatabase,
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
            ],
        ));
    }

    protected function friendlyDatabaseWorkspaceError(\Throwable $e, string $defaultMessage): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return $defaultMessage;
        }

        if (str_contains($message, 'SSH connection failed for server:')) {
            return $defaultMessage.' '.__('The server is not accepting Dply\'s SSH login right now for :connection.', [
                'connection' => $this->server->getSshConnectionString(),
            ]);
        }

        if (str_contains($message, 'Permission denied (publickey)')) {
            return $defaultMessage.' '.__('The server rejected the SSH key for :connection.', [
                'connection' => $this->server->getSshConnectionString(),
            ]);
        }

        return $message;
    }

    /**
     * @return array{username: string, password: string, grant_host: string}|null
     */
    protected function resolveExistingMysqlUser(): ?array
    {
        $reference = trim($this->new_db_existing_user_reference);
        if ($reference === '') {
            return null;
        }

        if (str_starts_with($reference, 'primary:')) {
            $databaseId = substr($reference, 8);
            $database = ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->whereIn('engine', DatabaseWorkspaceEngines::MYSQL_FAMILY)
                ->find($databaseId);

            if (! $database) {
                return null;
            }

            return [
                'username' => (string) $database->username,
                'password' => (string) $database->password,
                'grant_host' => 'localhost',
            ];
        }

        if (str_starts_with($reference, 'extra:')) {
            $extraId = substr($reference, 6);
            $extra = ServerDatabaseExtraUser::query()
                ->whereKey($extraId)
                ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id)->whereIn('engine', DatabaseWorkspaceEngines::MYSQL_FAMILY))
                ->first();

            if (! $extra) {
                return null;
            }

            return [
                'username' => (string) $extra->username,
                'password' => (string) $extra->password,
                'grant_host' => (string) ($extra->host ?: 'localhost'),
            ];
        }

        return null;
    }

    protected function maybeEmailCreatedDatabaseCredentials(ServerDatabase $database, ?string $plainPassword): bool
    {
        $user = auth()->user();
        $organization = $this->server->organization;

        if ($user === null || $organization === null || ! $organization->email_database_credentials_enabled) {
            return false;
        }

        Notification::send($user, new ServerDatabaseCredentialsNotification(
            server: $this->server,
            database: $database,
            password: $plainPassword,
        ));

        return true;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    protected function existingMysqlUserOptions(): array
    {
        $options = [];

        foreach ($this->server->serverDatabases->whereIn('engine', DatabaseWorkspaceEngines::MYSQL_FAMILY)->sortBy('name') as $database) {
            $options[] = [
                'id' => 'primary:'.$database->id,
                'label' => __('Primary user for :database (:username@localhost)', [
                    'database' => $database->name,
                    'username' => $database->username,
                ]),
            ];

            foreach ($database->extraUsers->sortBy('username') as $extraUser) {
                $options[] = [
                    'id' => 'extra:'.$extraUser->id,
                    'label' => __('Extra user for :database (:username@:host)', [
                        'database' => $database->name,
                        'username' => $extraUser->username,
                        'host' => $extraUser->host ?: 'localhost',
                    ]),
                ];
            }
        }

        return $options;
    }
}
