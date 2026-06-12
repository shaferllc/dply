<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerDatabaseExtraUser;
use App\Notifications\ServerDatabaseCredentialsNotification;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Notifications\ServerDatabaseNotificationDispatcher;
use App\Services\Servers\DatabaseEngineReadinessGuard;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDatabaseCrud
{
    public string $new_db_name = '';

    public string $new_db_engine = 'mysql';

    public bool $engine_create_form_open = false;

    /** When the create-database modal is open, whether the engine is fixed (per-engine tab) or selectable (Basics tab). */
    public bool $create_lock_engine = false;

    public string $new_db_username = '';

    public string $new_db_password = '';

    public string $new_db_user_mode = 'new';

    public string $new_db_existing_user_reference = '';

    public string $new_db_host = '127.0.0.1';

    public ?string $new_db_description = null;

    public ?string $new_mysql_charset = null;

    public ?string $new_mysql_collation = null;

    /** @var array{name: string, engine: string, username: string, password: string, host: string, password_generated: bool, username_generated: bool}|null */
    public ?array $generated_database_credentials = null;

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
        $this->create_lock_engine = true;
        $this->resetCreateDatabaseFormFields();
        $this->resetErrorBag();
        $this->engine_create_form_open = true;
        $this->dispatch('open-modal', 'create-database-modal');
    }

    /**
     * Open the create-database modal from the Basics tab, where the engine is
     * selectable rather than fixed to a single per-engine tab.
     */
    public function openDatabaseCreate(): void
    {
        $this->authorize('update', $this->server);

        $this->create_lock_engine = false;
        $this->resetCreateDatabaseFormFields();
        $this->resetErrorBag();
        $this->engine_create_form_open = true;
        $this->dispatch('open-modal', 'create-database-modal');
    }

    public function closeEngineDatabaseCreate(): void
    {
        $this->dispatch('close-modal', 'create-database-modal');
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

    public function generateNewDbPassword(): void
    {
        $this->new_db_password = ServerDatabase::generateConnectionSafePassword();
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

        $readiness = app(DatabaseEngineReadinessGuard::class)->check($this->server, $this->new_db_engine);
        if (! $readiness['ok']) {
            $this->addError('new_db_engine', (string) $readiness['reason']);

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
            $this->dispatch('close-modal', 'create-database-modal');
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
