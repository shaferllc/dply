<?php

namespace App\Livewire\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAdminCredential;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseBackup;
use App\Models\ServerDatabaseCredentialShare;
use App\Models\ServerDatabaseExtraUser;
use App\Models\NotificationSubscription;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Notifications\ServerDatabaseNotificationDispatcher;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseDriftAnalyzer;
use App\Services\Servers\ServerDatabaseHostCapabilities;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerDatabaseRemoteExec;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\ServerDatabaseNotificationKeys;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class WorkspaceDatabases extends Component
{
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use WithFileUploads;

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

    /** @var array{name: string, engine: string, username: string, password: string, password_generated: bool, username_generated: bool}|null */
    public ?array $generated_database_credentials = null;

    public bool $db_engine_default_applied = false;

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

    /** @var array<string, array{created: bool, removed: bool}> */
    public array $databaseAlertMatrix = [];

    /** @var list<array{id: string, label: string}> */
    public array $databaseAlertChannelRows = [];

    /** @var array<string, mixed>|null */
    public ?array $drift_snapshot = null;

    public $import_sql_file = null;

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
        }
        $this->loadDatabaseAlertMatrix();
    }

    public function refreshDatabaseCapabilities(ServerDatabaseHostCapabilities $capabilities): void
    {
        $this->authorize('update', $this->server);
        $capabilities->forget($this->server);
        $this->db_engine_default_applied = false;
        $this->flash_error = null;
        $this->flash_success = __('Rechecked the server for database engines.');
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = ['databases', 'advanced'];
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'databases';
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

    public function dismissGeneratedDatabaseCredentials(): void
    {
        $this->generated_database_credentials = null;
    }

    public function closeShareLinkModal(): void
    {
        $this->share_link_modal_url = null;
        $this->share_link_modal_db_name = null;
    }

    public function saveDatabaseAlertPreferences(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->flash_error = __('Deployers cannot change notification routing.');

            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $channels = AssignableNotificationChannels::forUser($user, $user->currentOrganization());
        $allowedIds = $channels->pluck('id')->map(fn ($id) => (string) $id)->all();

        DB::transaction(function () use ($channels, $allowedIds): void {
            foreach ($channels as $channel) {
                $cid = (string) $channel->id;
                if (! in_array($cid, $allowedIds, true)) {
                    continue;
                }

                $row = $this->databaseAlertMatrix[$cid] ?? [];
                foreach (ServerDatabaseNotificationKeys::KINDS as $kind) {
                    $wanted = (bool) ($row[$kind] ?? false);
                    $eventKey = ServerDatabaseNotificationKeys::eventKey($kind);
                    $q = NotificationSubscription::query()
                        ->where('notification_channel_id', $channel->id)
                        ->where('subscribable_type', Server::class)
                        ->where('subscribable_id', $this->server->id)
                        ->where('event_key', $eventKey);

                    if ($wanted) {
                        NotificationSubscription::query()->firstOrCreate([
                            'notification_channel_id' => $channel->id,
                            'subscribable_type' => Server::class,
                            'subscribable_id' => $this->server->id,
                            'event_key' => $eventKey,
                        ]);
                    } else {
                        $q->delete();
                    }
                }
            }
        });

        $this->flash_success = __('Database notification routing updated.');
        $this->flash_error = null;
        $this->loadDatabaseAlertMatrix();
    }

    public function saveAdminCredentials(
        ServerDatabaseHostCapabilities $capabilities,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
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
        $capabilities->forget($this->server);
        $this->db_engine_default_applied = false;

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_ADMIN_CREDENTIALS_SAVED, [], auth()->user());
        $this->flash_success = __('Saved database admin credentials.');
        $this->flash_error = null;
    }

    public function clearStoredMysqlRootPassword(
        ServerDatabaseHostCapabilities $capabilities,
    ): void {
        $this->authorize('update', $this->server);
        $cred = ServerDatabaseAdminCredential::query()->where('server_id', $this->server->id)->first();
        if ($cred) {
            $cred->mysql_root_password = null;
            $cred->save();
        }
        $capabilities->forget($this->server);
        $this->db_engine_default_applied = false;
        $this->flash_success = __('Cleared stored MySQL root password.');
    }

    public function clearStoredPostgresPassword(ServerDatabaseHostCapabilities $capabilities): void
    {
        $this->authorize('update', $this->server);
        $cred = ServerDatabaseAdminCredential::query()->where('server_id', $this->server->id)->first();
        if ($cred) {
            $cred->postgres_password = null;
            $cred->save();
        }
        $capabilities->forget($this->server);
        $this->db_engine_default_applied = false;
        $this->flash_success = __('Cleared stored PostgreSQL password.');
    }

    public function runDriftAnalysis(
        ServerDatabaseDriftAnalyzer $driftAnalyzer,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        $this->drift_snapshot = $driftAnalyzer->analyze($this->server);
        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DRIFT_CHECK, [], auth()->user());
        $this->flash_success = __('Drift analysis updated.');
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
        if ($db->engine !== 'mysql') {
            $this->addError('extra_db_id', __('Extra users in this flow are limited to MySQL databases.'));

            return;
        }

        $extra = ServerDatabaseExtraUser::query()->create([
            'server_database_id' => $db->id,
            'username' => $this->extra_username,
            'password' => $this->extra_password,
            'host' => $this->extra_host,
        ]);

        $provisioner->createExtraMysqlUser($db, $extra);
        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_EXTRA_USER_CREATED, [
            'server_database_id' => $db->id,
            'username' => $extra->username,
        ], auth()->user());

        $this->extra_username = '';
        $this->extra_password = '';
        $this->flash_success = __('Extra user created on the server.');
    }

    public function removeExtraUser(
        string $extraId,
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseHostCapabilities $capabilities,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;

        $row = ServerDatabaseExtraUser::query()
            ->whereKey($extraId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->with(['serverDatabase.server'])
            ->firstOrFail();

        $db = $row->serverDatabase;
        if ($db->engine !== 'mysql') {
            $dbId = $row->server_database_id;
            $user = $row->username;
            $row->delete();
            $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_EXTRA_USER_REMOVED, [
                'server_database_id' => $dbId,
                'username' => $user,
                'dropped_remote' => false,
            ], auth()->user());
            $this->flash_success = __('Removed extra user from Dply.');

            return;
        }

        $caps = $capabilities->forServer($this->server);

        if (! $caps['mysql']) {
            $this->flash_error = __('MySQL is not reachable over SSH; cannot drop the remote user safely.');

            return;
        }

        try {
            $provisioner->dropExtraMysqlUser($db, $row);
        } catch (\Throwable $e) {
            $this->flash_error = __('Could not drop user on the server: :msg', ['msg' => $e->getMessage()]);

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

        $this->flash_success = __('Dropped the MySQL user on the server and removed it from Dply.');
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
        $this->flash_success = __('Share link created.');
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
        $this->flash_success = __('Export queued. Refresh this page in a few moments and download from the backup list.');
    }

    public function downloadBackup(string $backupId): StreamedResponse|Response|null
    {
        $this->authorize('update', $this->server);
        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->firstOrFail();

        if ($backup->status !== ServerDatabaseBackup::STATUS_COMPLETED || empty($backup->disk_path)) {
            $this->flash_error = __('Backup is not ready yet.');

            return null;
        }

        if (! Storage::disk('local')->exists($backup->disk_path)) {
            $this->flash_error = __('Backup file is missing from storage.');

            return null;
        }

        return Storage::disk('local')->download($backup->disk_path, 'database-'.$backup->id.'.sql');
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
            $this->flash_error = __('Could not read the uploaded file.');

            return;
        }

        try {
            if ($db->engine === 'postgres') {
                $remoteExec->postgresImportFromString($db->server, $db->name, $db->username, $db->password, $contents, 600, $maxBytes);
            } else {
                $remoteExec->mysqlImportFromString($db->server, $db->name, $db->username, $db->password, $contents, 600, $maxBytes);
            }
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();

            return;
        }

        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_IMPORT_RAN, [
            'server_database_id' => $db->id,
            'bytes' => strlen($contents),
        ], auth()->user());
        $this->import_sql_file = null;
        $this->flash_success = __('Import finished.');
    }

    public function synchronizeDatabases(
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseHostCapabilities $capabilities,
        ServerDatabaseAuditLogger $auditLogger,
    ): void {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;

        $caps = $capabilities->forServer($this->server);
        if (! $caps['mysql'] && ! $caps['postgres']) {
            $this->flash_error = __('No supported database engine is reachable on this server (MySQL/MariaDB as root, or PostgreSQL via the postgres user).');

            return;
        }

        try {
            $this->remote_mysql_databases = $caps['mysql']
                ? $provisioner->listMysqlDatabaseNames($this->server)
                : [];
            try {
                $this->remote_postgres_databases = $caps['postgres']
                    ? $provisioner->listPostgresDatabaseNames($this->server)
                    : [];
            } catch (\Throwable) {
                $this->remote_postgres_databases = [];
            }
            $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_SYNC_RAN, [
                'mysql_count' => count($this->remote_mysql_databases),
                'postgres_count' => count($this->remote_postgres_databases),
            ], auth()->user());
            $this->flash_success = __('Queried the server for database names. Compare the lists below and add or import anything missing in Dply.');
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function prefillDatabaseFromDiscovery(
        string $name,
        string $engine,
        ServerDatabaseHostCapabilities $capabilities,
    ): void {
        $this->authorize('update', $this->server);
        $caps = $capabilities->forServer($this->server);
        $engine = $engine === 'postgres' ? 'postgres' : 'mysql';
        if ($engine === 'mysql' && ! $caps['mysql']) {
            $this->flash_error = __('MySQL/MariaDB is not available on this server.');

            return;
        }
        if ($engine === 'postgres' && ! $caps['postgres']) {
            $this->flash_error = __('PostgreSQL is not available on this server.');

            return;
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
        ServerDatabaseHostCapabilities $capabilities,
        ServerDatabaseAuditLogger $auditLogger,
        ServerDatabaseNotificationDispatcher $notificationDispatcher,
    ): void {
        $this->authorize('update', $this->server);
        $this->new_db_username = trim($this->new_db_username);

        $caps = $capabilities->forServer($this->server);
        if ($this->new_db_engine === 'mysql' && ! $caps['mysql']) {
            $this->addError('new_db_engine', __('MySQL/MariaDB is not installed or root access from SSH is not available on this server.'));

            return;
        }
        if ($this->new_db_engine === 'postgres' && ! $caps['postgres']) {
            $this->addError('new_db_engine', __('PostgreSQL is not installed or the postgres role is not reachable over SSH.'));

            return;
        }

        $rules = [
            'new_db_name' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/',
            'new_db_engine' => 'required|in:mysql,postgres',
            'new_db_host' => 'required|string|max:255',
            'new_db_description' => 'nullable|string|max:2000',
            'new_mysql_charset' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]*$/',
            'new_mysql_collation' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_]*$/',
            'new_db_user_mode' => 'required|in:new,existing',
            'new_db_existing_user_reference' => 'nullable|string|max:100',
        ];
        if ($this->new_db_user_mode === 'existing' && $this->new_db_engine === 'mysql') {
            $rules['new_db_existing_user_reference'] = 'required|string|max:100';
            $rules['new_db_username'] = 'nullable';
        } elseif ($this->new_db_username !== '') {
            $rules['new_db_username'] = 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/';
        } else {
            $rules['new_db_username'] = 'nullable';
        }
        if ($this->new_db_user_mode === 'existing' && $this->new_db_engine === 'mysql') {
            $rules['new_db_password'] = 'nullable';
        } elseif ($this->new_db_password !== null && $this->new_db_password !== '') {
            $rules['new_db_password'] = 'required|string|max:200';
        } else {
            $rules['new_db_password'] = 'nullable';
        }
        $this->validate($rules);

        $this->flash_success = null;
        $this->flash_error = null;

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

        $username = $existingMysqlUser['username'] ?? $this->new_db_username;
        $usernameGenerated = false;
        if ($username === '') {
            $base = Str::slug($this->new_db_name, '_');
            if ($base === '') {
                $base = 'db';
            }
            $username = Str::limit($base, 28, '').'_'.Str::lower(Str::random(4));
            $usernameGenerated = true;
        }

        $password = $existingMysqlUser['password'] ?? $this->new_db_password;
        $passwordGenerated = false;
        if ($password === null || $password === '') {
            $password = Str::password(24);
            $passwordGenerated = true;
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
            $out = $existingMysqlUser
                ? $provisioner->createMysqlDatabaseForExistingUser($db, $existingMysqlUser['grant_host'])
                : $provisioner->createOnServer($db);
            $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DATABASE_CREATED, [
                'server_database_id' => $db->id,
                'engine' => $db->engine,
                'name' => $db->name,
                'used_existing_user' => $existingMysqlUser !== null,
            ], auth()->user());
            $notificationDispatcher->notifyIfSubscribed($this->server, 'created', $db, auth()->user());
            $this->flash_success = __('Database provisioned on the server.').' '.Str::limit($out, 500);
            $this->generated_database_credentials = [
                'name' => $db->name,
                'engine' => $db->engine,
                'username' => $db->username,
                'password' => $db->password,
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
            $this->flash_error = $e->getMessage();
        }
    }

    public function deleteDatabase(
        string $id,
        ServerDatabaseAuditLogger $auditLogger,
        ServerDatabaseNotificationDispatcher $notificationDispatcher,
    ): void
    {
        $this->authorize('update', $this->server);
        $db = ServerDatabase::query()->where('server_id', $this->server->id)->findOrFail($id);
        $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DATABASE_REMOVED_DPLY, [
            'server_database_id' => $db->id,
            'name' => $db->name,
        ], auth()->user());
        $notificationDispatcher->notifyIfSubscribed($this->server, 'removed', $db, auth()->user(), false);
        $db->delete();
        $this->flash_success = __('Removed this database from Dply. The database was not dropped on the server.');
        $this->flash_error = null;
        if ($this->credentials_modal_db_id === $id) {
            $this->credentials_modal_db_id = null;
        }
    }

    public function dropDatabaseOnServer(
        string $id,
        ServerDatabaseProvisioner $provisioner,
        ServerDatabaseHostCapabilities $capabilities,
        ServerDatabaseAuditLogger $auditLogger,
        ServerDatabaseNotificationDispatcher $notificationDispatcher,
    ): void {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->findOrFail($id);
        $caps = $capabilities->forServer($this->server);
        if ($db->engine === 'mysql' && ! $caps['mysql']) {
            $this->flash_error = __('MySQL/MariaDB is not available on this server, so the database cannot be dropped remotely.');

            return;
        }
        if ($db->engine === 'postgres' && ! $caps['postgres']) {
            $this->flash_error = __('PostgreSQL is not available on this server, so the database cannot be dropped remotely.');

            return;
        }

        try {
            $out = $provisioner->dropFromServer($db);
            $auditLogger->record($this->server, ServerDatabaseAuditEvent::EVENT_DATABASE_DROPPED_REMOTE, [
                'server_database_id' => $db->id,
                'name' => $db->name,
            ], auth()->user());
            $notificationDispatcher->notifyIfSubscribed($this->server, 'removed', $db, auth()->user(), true);
            $db->delete();
            $this->flash_success = __('Database and user were dropped on the server and the entry was removed from Dply.').' '.Str::limit($out, 400);
            if ($this->credentials_modal_db_id === $id) {
                $this->credentials_modal_db_id = null;
            }
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function render(
        ServerDatabaseHostCapabilities $capabilitiesService,
        ServerDatabaseDriftAnalyzer $driftAnalyzer,
    ): View {
        $this->server->refresh();
        $this->server->load([
            'serverDatabases.extraUsers',
            'databaseAuditEvents' => fn ($q) => $q->limit(80),
        ]);

        $capabilities = ['mysql' => false, 'postgres' => false, 'redis' => false];
        try {
            $capabilities = $capabilitiesService->forServer($this->server);
        } catch (\Throwable $e) {
            $this->flash_error = $this->friendlyDatabaseWorkspaceError(
                $e,
                __('Dply could not connect to the server to check database engines.')
            );
        }

        if (! $this->db_engine_default_applied) {
            if (! $capabilities['mysql'] && $capabilities['postgres']) {
                $this->new_db_engine = 'postgres';
            } elseif ($capabilities['mysql'] && ! $capabilities['postgres']) {
                $this->new_db_engine = 'mysql';
            }
            $this->db_engine_default_applied = true;
        }

        $credentialsModalDatabase = null;
        if ($this->credentials_modal_db_id !== null) {
            $credentialsModalDatabase = ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->find($this->credentials_modal_db_id);
        }

        if ($this->workspace_tab === 'advanced' && $this->drift_snapshot === null) {
            try {
                $this->drift_snapshot = $driftAnalyzer->analyze($this->server);
            } catch (\Throwable $e) {
                $this->flash_error = $this->friendlyDatabaseWorkspaceError(
                    $e,
                    __('Dply could not connect to the server to compare database drift.')
                );
            }
        }

        $recentBackups = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        $this->server->loadMissing('organization');
        $org = $this->server->organization;
        $orgAllowsCredentialShares = $org ? $org->allowsDatabaseCredentialShares() : true;
        $databaseImportMaxBytes = $org
            ? $org->databaseImportMaxBytes()
            : (int) config('server_database.import_max_bytes', 10485760);

        return view('livewire.servers.workspace-databases', [
            'capabilities' => $capabilities,
            'credentialsModalDatabase' => $credentialsModalDatabase,
            'existingMysqlUserOptions' => $this->existingMysqlUserOptions(),
            'recentBackups' => $recentBackups,
            'orgAllowsCredentialShares' => $orgAllowsCredentialShares,
            'databaseImportMaxBytes' => $databaseImportMaxBytes,
            'isDeployer' => $this->currentUserIsDeployer(),
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

    protected function loadDatabaseAlertMatrix(): void
    {
        $this->databaseAlertMatrix = [];
        $this->databaseAlertChannelRows = [];

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $channels = AssignableNotificationChannels::forUser($user, $user->currentOrganization());
        foreach ($channels as $channel) {
            $cid = (string) $channel->id;
            $entry = [
                'created' => false,
                'removed' => false,
            ];

            foreach (ServerDatabaseNotificationKeys::KINDS as $kind) {
                $entry[$kind] = NotificationSubscription::query()
                    ->where('notification_channel_id', $channel->id)
                    ->where('subscribable_type', Server::class)
                    ->where('subscribable_id', $this->server->id)
                    ->where('event_key', ServerDatabaseNotificationKeys::eventKey($kind))
                    ->exists();
            }

            $this->databaseAlertMatrix[$cid] = $entry;
            $this->databaseAlertChannelRows[] = [
                'id' => $cid,
                'label' => (string) $channel->label,
            ];
        }
    }
}
