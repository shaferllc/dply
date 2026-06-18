<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAdminCredential;
use App\Models\ServerDatabaseAuditEvent;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Support\Servers\DatabaseWorkspaceEngines;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDatabaseAdminCredentials
{
    public string $admin_mysql_root_username = 'root';

    public string $admin_mysql_root_password = '';

    public string $admin_postgres_superuser = 'postgres';

    public string $admin_postgres_password = '';

    public bool $admin_postgres_use_sudo = true;

    public string $admin_mongodb_username = 'admin';

    public string $admin_mongodb_password = '';

    public string $admin_clickhouse_username = 'default';

    public string $admin_clickhouse_password = '';

    public function generateAdminMysqlRootPassword(): void
    {
        $this->admin_mysql_root_password = ServerDatabase::generateConnectionSafePassword();
    }

    public function generateAdminPostgresPassword(): void
    {
        $this->admin_postgres_password = ServerDatabase::generateConnectionSafePassword();
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
}
