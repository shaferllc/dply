<?php

namespace App\Livewire\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\InstallDatabaseEngineJob;
use App\Jobs\UninstallDatabaseEngineJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Livewire\Servers\Concerns\RunsServerConsoleActions;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAdminCredential;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseBackup;
use App\Models\ServerDatabaseCredentialShare;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseExtraUser;
use App\Services\Notifications\ServerDatabaseNotificationDispatcher;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseDriftAnalyzer;
use App\Services\Servers\ServerDatabaseHostCapabilities;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerDatabaseRemoteExec;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    use WithFileUploads;

    #[Url(as: 'tab', except: 'databases', history: true)]
    public string $workspace_tab = 'databases';

    public string $new_db_name = '';

    public string $new_db_engine = 'mysql';

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

    public ?string $credentials_modal_db_id = null;

    public ?string $connection_url_modal_db_id = null;

    /** @var array{name: string, engine: string, username: string, password: string, host: string, password_generated: bool, username_generated: bool}|null */
    public ?array $generated_database_credentials = null;

    public string $admin_mysql_root_username = 'root';

    public string $admin_mysql_root_password = '';

    public string $admin_postgres_superuser = 'postgres';

    public string $admin_postgres_password = '';

    public bool $admin_postgres_use_sudo = true;

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
        }

        $meta = $server->meta ?? [];
        $this->manage_db_bind_host = (string) ($meta['manage_db_bind_host'] ?? '');
        $port = $meta['manage_db_port'] ?? null;
        $this->manage_db_port = is_numeric($port) ? (int) $port : null;
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
    public string $engine_subtab = 'overview';

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = ['databases', 'advanced', 'notifications', 'mysql', 'postgres', 'sqlite'];
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'databases';
        // Reset sub-tab on every top-level switch so engines always open on the
        // actionable view — without this the operator clicking from MySQL→Info
        // then Postgres would land on Postgres→Info, hiding the actions.
        $this->engine_subtab = 'overview';
    }

    public function setEngineSubtab(string $subtab): void
    {
        $allowed = ['overview', 'info'];
        $this->engine_subtab = in_array($subtab, $allowed, true) ? $subtab : 'overview';
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

        InstallDatabaseEngineJob::dispatch($row->id);
        $this->toastSuccess(__('Installing :engine — refresh in a moment to see status.', ['engine' => $engine]));
        $this->workspace_tab = $engine;
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

        UninstallDatabaseEngineJob::dispatch($row->id);

        $this->toastSuccess(__('Stopping :engine install and reverting. Apt purge runs in the background.', [
            'engine' => $engine,
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

        UninstallDatabaseEngineJob::dispatch($row->id);
        $this->toastSuccess(__('Uninstall queued for :engine.', ['engine' => $engine]));
    }

    public function refreshDatabaseCapabilities(ServerDatabaseHostCapabilities $capabilities): void
    {
        $this->authorize('update', $this->server);
        $capabilities->forget($this->server);
        $this->toastSuccess(__('Rechecked the server for database engines.'));
    }

    public function generateNewDbPassword(): void
    {
        $this->new_db_password = Str::password(24);
    }

    public function updatedNewDbEngine(string $value): void
    {
        if ($value !== 'mysql') {
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
        if ($db->engine === 'mysql') {
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

        if ($db->engine === 'mysql') {
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

    public function closeShareLinkModal(): void
    {
        $this->share_link_modal_url = null;
        $this->share_link_modal_db_name = null;
    }

    public function saveAdminCredentials(ServerDatabaseAuditLogger $auditLogger): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'admin_mysql_root_username' => 'required|string|max:64',
            'admin_postgres_superuser' => 'required|string|max:64',
            'admin_postgres_use_sudo' => 'boolean',
            'admin_mysql_root_password' => 'nullable|string|max:500',
            'admin_postgres_password' => 'nullable|string|max:500',
        ]);

        $cred = ServerDatabaseAdminCredential::query()->firstOrNew(['server_id' => $this->server->id]);
        $cred->mysql_root_username = $this->admin_mysql_root_username;
        $cred->postgres_superuser = $this->admin_postgres_superuser;
        $cred->postgres_use_sudo = $this->admin_postgres_use_sudo;
        if ($this->admin_mysql_root_password !== '') {
            $cred->mysql_root_password = $this->admin_mysql_root_password;
        }
        if ($this->admin_postgres_password !== '') {
            $cred->postgres_password = $this->admin_postgres_password;
        }
        $cred->save();

        $this->admin_mysql_root_password = '';
        $this->admin_postgres_password = '';

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_ADMIN_CREDENTIALS_SAVED, [], auth()->user());
        $this->toastSuccess(__('Saved database admin credentials.'));
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

    public function queueExport(string $databaseId, ServerDatabaseAuditLogger $auditLogger): void
    {
        $this->authorize('update', $this->server);
        $db = ServerDatabase::query()->where('server_id', $this->server->id)->whereKey($databaseId)->firstOrFail();
        $backup = ServerDatabaseBackup::query()->create([
            'server_database_id' => $db->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);
        dispatch(new ExportServerDatabaseBackupJob($backup->id));
        $this->toastSuccess(__('Export queued. Refresh this page in a few moments and download from the backup list.'));
    }

    public function downloadBackup(string $backupId): StreamedResponse|Response|null
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

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_SYNC_RAN, [
            'mysql_count' => count($this->remote_mysql_databases),
            'postgres_count' => count($this->remote_postgres_databases),
        ], auth()->user());
        $this->toastSuccess(__('Queried the server for database names. Compare the lists below and add or import anything missing in Dply.'));
    }

    public function prefillDatabaseFromDiscovery(string $name, string $engine): void
    {
        $this->authorize('update', $this->server);
        $engine = $engine === 'postgres' ? 'postgres' : 'mysql';
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
            $engineLabel = match ($this->new_db_engine) {
                'mysql' => 'MySQL/MariaDB',
                'postgres' => 'PostgreSQL',
                'sqlite' => 'SQLite',
                default => $this->new_db_engine,
            };
            $this->addError('new_db_engine', __(':engine is not installed on this server.', ['engine' => $engineLabel]));

            return;
        }

        $rules = [
            'new_db_name' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/',
            'new_db_engine' => 'required|in:mysql,postgres,sqlite',
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
        } elseif ($this->new_db_user_mode === 'existing' && $this->new_db_engine === 'mysql') {
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
        $this->validate($rules);

        // Force 'new' for non-MySQL engines so a stale form value
        // (operator switched from MySQL → SQLite without re-rendering)
        // can't trip the existing-user branch below.
        if ($this->new_db_engine !== 'mysql') {
            $this->new_db_user_mode = 'new';
        }

        $existingMysqlUser = null;
        if ($this->new_db_user_mode === 'existing') {
            if ($this->new_db_engine !== 'mysql') {
                $this->addError('new_db_user_mode', __('Existing user selection is currently supported for MySQL only.'));

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
            $password = Str::password(24);
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
                function (\App\Services\ConsoleActions\ConsoleEmitter $emit) use ($provisioner, $db, $existingMysqlUser, $auditLogger): string {
                    $emit->step('db', sprintf('CREATE %s DATABASE %s', strtoupper($db->engine), $db->name));
                    $out = $existingMysqlUser
                        ? $provisioner->createMysqlDatabaseForExistingUser($db, $existingMysqlUser['grant_host'])
                        : $provisioner->createOnServer($db);
                    foreach (preg_split("/\r?\n/", (string) $out) ?: [] as $line) {
                        if ($line !== '') {
                            $emit($line, \App\Models\ConsoleAction::LEVEL_INFO, 'db');
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
            $this->toastSuccess(__('Database provisioned on the server.').' '.Str::limit($out, 500));
            $this->generated_database_credentials = [
                'name' => $db->name,
                'engine' => $db->engine,
                'username' => $db->username,
                'password' => $db->password,
                'host' => $db->host,
                'username_generated' => $usernameGenerated,
                'password_generated' => $passwordGenerated,
            ];
            $this->new_db_name = '';
            $this->new_db_user_mode = 'new';
            $this->new_db_existing_user_reference = '';
            $this->new_db_username = '';
            $this->new_db_password = '';
            $this->new_db_description = null;
            $this->new_mysql_charset = null;
            $this->new_mysql_collation = null;
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
                function (\App\Services\ConsoleActions\ConsoleEmitter $emit) use ($provisioner, $db, $auditLogger): string {
                    $emit->step('db', sprintf('DROP %s DATABASE %s', strtoupper($db->engine), $db->name));
                    $out = $provisioner->dropFromServer($db);
                    foreach (preg_split("/\r?\n/", (string) $out) ?: [] as $line) {
                        if ($line !== '') {
                            $emit($line, \App\Models\ConsoleAction::LEVEL_INFO, 'db');
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
        $this->server->load([
            'serverDatabases.extraUsers',
            'databaseAuditEvents' => fn ($q) => $q->with('user:id,name')->limit(80),
        ]);

        $capabilities = ['mysql' => false, 'postgres' => false, 'sqlite' => false];
        try {
            $capabilities = $capabilitiesService->forServer($this->server);
        } catch (\Throwable) {
            // Probe failures (SSH timeout, key issues) leave engine tabs hidden.
            // The user can still create databases from Basics — provisioner errors surface there.
        }

        if (! ($capabilities[$this->new_db_engine] ?? false)) {
            foreach (['mysql', 'postgres', 'sqlite'] as $engine) {
                if ($capabilities[$engine] ?? false) {
                    $this->new_db_engine = $engine;
                    break;
                }
            }
        }

        // Engine tabs are now ALWAYS reachable — even when an engine isn't installed yet, the
        // operator needs to land on the tab to click the Install button. The capability probe
        // still drives what the tab renders (status panel vs install CTA); it just doesn't gate
        // navigability anymore.
        $allowedTabs = ['databases', 'advanced', 'notifications', 'mysql', 'postgres', 'sqlite'];
        if (! in_array($this->workspace_tab, $allowedTabs, true)) {
            $this->workspace_tab = 'databases';
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

        if ($this->workspace_tab === 'advanced' && $this->drift_snapshot === null) {
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

        $recentBackups = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->with('serverDatabase')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        // Group by engine so each engine tab can render only its own backups.
        $recentBackupsByEngine = $recentBackups->groupBy(fn ($b) => $b->serverDatabase?->engine ?? 'unknown');

        $this->server->loadMissing('organization');
        $org = $this->server->organization;
        $orgAllowsCredentialShares = $org ? $org->allowsDatabaseCredentialShares() : true;
        $databaseImportMaxBytes = $org
            ? $org->databaseImportMaxBytes()
            : (int) config('server_database.import_max_bytes', 10485760);

        // Engine rows keyed by engine name so the per-engine tabs can render install/status
        // without repeated queries. Sqlite doesn't have a row in this table — it's a binary
        // that ships with the others.
        $engineRows = ServerDatabaseEngine::query()
            ->where('server_id', $this->server->id)
            ->get()
            ->keyBy('engine');

        // Per-engine console-action runs (db_create/db_drop/etc). Engines that have an
        // actual row get the per-row banner; sqlite (no row) falls back to a server-scoped
        // lookup so its create/drop banners still surface on the sqlite subtab.
        $dbRunsByEngine = [];
        foreach (['mysql', 'postgres'] as $engine) {
            $row = $engineRows->get($engine);
            if ($row !== null) {
                $dbRunsByEngine[$engine] = $this->latestConsoleActionFor($row, 'db_');
            }
        }
        $dbRunsByEngine['sqlite'] = $this->latestConsoleActionFor($this->server, 'db_');

        // Latest non-dismissed manage_action run for this server. Drives the
        // Show processlist output banner on the MySQL → Info subtab. Picks up
        // any `manage_*` allowlisted action (also fires from the Caches workspace
        // for `redis_info`), but the banner is rendered only inside the MySQL tab.
        $manageActionRun = \App\Models\ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        return view('livewire.servers.workspace-databases', [
            'capabilities' => $capabilities,
            'credentialsModalDatabase' => $credentialsModalDatabase,
            'connectionUrlModalDatabase' => $connectionUrlModalDatabase,
            'existingMysqlUserOptions' => $this->existingMysqlUserOptions(),
            'recentBackups' => $recentBackups,
            'recentBackupsByEngine' => $recentBackupsByEngine,
            'orgAllowsCredentialShares' => $orgAllowsCredentialShares,
            'databaseImportMaxBytes' => $databaseImportMaxBytes,
            'engineRows' => $engineRows,
            'dbRunsByEngine' => array_filter($dbRunsByEngine),
            // Allowlisted manage actions exposed in the Databases workspace
            // (currently just `mysql_processlist`). Banner-only — see
            // RunsAllowlistedManageAction. Migrated here when /manage/data was retired.
            'serviceActions' => config('server_manage.service_actions', []),
            'manageActionRun' => $manageActionRun,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
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
                ->where('engine', 'mysql')
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
                ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id)->where('engine', 'mysql'))
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

    /**
     * @return list<array{id: string, label: string}>
     */
    protected function existingMysqlUserOptions(): array
    {
        $options = [];

        foreach ($this->server->serverDatabases->where('engine', 'mysql')->sortBy('name') as $database) {
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
