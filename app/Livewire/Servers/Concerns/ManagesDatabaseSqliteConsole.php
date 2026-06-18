<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseProvisioner;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDatabaseSqliteConsole
{
    /** State for the SQLite SQL console modal. */
    public ?string $sqlite_console_db_id = null;

    public string $sqlite_console_sql = '';

    public string $sqlite_console_output = '';

    public ?int $sqlite_console_exit_code = null;

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
}
