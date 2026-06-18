<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Support\Servers\DatabaseWorkspaceEngines;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDatabaseEdit
{
    /** State for the unified Edit modal (engine-aware). */
    public ?string $editing_db_id = null;

    public string $editing_db_engine = '';

    public string $editing_db_name = '';

    public string $edit_description = '';

    public string $edit_mysql_charset = '';

    public string $edit_mysql_collation = '';

    public string $edit_sqlite_path = '';

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
}
