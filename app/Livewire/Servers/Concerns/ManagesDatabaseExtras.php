<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseExtraUser;
use App\Services\Servers\PostgresExtensionManager;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Support\Servers\PostgresExtensionCatalog;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDatabaseExtras
{
    /** @var list<string> */
    public array $postgres_installed_extensions = [];

    public string $extra_db_id = '';

    public string $extra_username = '';

    public string $extra_password = '';

    public string $extra_host = 'localhost';

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

        $this->dispatchDatabaseNotification('user_created', [
            __('User: :user', ['user' => $extra->username]),
            __('Database: :name', ['name' => $db->name]),
        ], ['server_database_id' => $db->id, 'username' => $extra->username, 'engine' => $db->engine]);

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

        $this->dispatchDatabaseNotification('user_removed', [
            __('User: :user', ['user' => $user]),
            __('Database: :name', ['name' => $db?->name ?? $dbId]),
        ], ['server_database_id' => $dbId, 'username' => $user]);

        $this->toastSuccess(__('Dropped the MySQL user on the server and removed it from Dply.'));
    }
}
