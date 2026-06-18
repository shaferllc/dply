<?php

namespace App\Modules\Backups\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseBackup;
use App\Models\User;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Modules\Notifications\Services\ServerBackupNotificationDispatcher;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseAuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Number;

class ExportServerDatabaseBackupJob implements ShouldQueue
{
    use Queueable, WritesConsoleAction;

    public int $timeout = 3600;

    private ?Model $consoleSubjectCache = null;

    /**
     * @param  string|null  $seededConsoleRunId  a ConsoleAction the dispatcher
     *                                           (an on-demand run) pre-seeded so progress streams into the Backups-tab
     *                                           banner. Null for scheduled runs — they stay silent (no console row).
     */
    public function __construct(
        public string $backupId,
        public ?string $seededConsoleRunId = null,
    ) {
        $q = config('server_database.export_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    /**
     * Resolve the banner's subject from the SEEDED row, not from the database's
     * home server — an on-demand backup of a remote-attached database is seeded
     * against the workspace server the operator is viewing, which may differ
     * from where the dump runs. Falling back to the home server only matters if
     * the row vanished (then the update no-ops anyway).
     */
    protected function consoleSubject(): Model
    {
        if ($this->consoleSubjectCache !== null) {
            return $this->consoleSubjectCache;
        }

        if ($this->seededConsoleRunId !== null) {
            $subject = ConsoleAction::query()->whereKey($this->seededConsoleRunId)->first()?->subject;
            if ($subject instanceof Model) {
                return $this->consoleSubjectCache = $subject;
            }
        }

        $server = ServerDatabaseBackup::query()->with('serverDatabase.server')->find($this->backupId)?->serverDatabase?->server;
        if (! $server instanceof Server) {
            throw new \RuntimeException('Console subject server not found for database backup.');
        }

        return $this->consoleSubjectCache = $server;
    }

    protected function consoleKind(): string
    {
        return 'backup_database';
    }

    public function handle(DatabaseBackupExporter $exporter, ServerDatabaseAuditLogger $auditLogger, ServerBackupNotificationDispatcher $notifications): void
    {
        $backup = ServerDatabaseBackup::query()->with(['serverDatabase.server'])->find($this->backupId);
        if (! $backup) {
            return;
        }

        $db = $backup->serverDatabase;
        if ($db === null) {
            return;
        }

        $server = $db->server;
        if (! $server instanceof Server) {
            return;
        }
        // the banner streams. Scheduled runs pass no id → a no-op emitter, and
        // the complete/fail console transitions below are no-ops too.
        $emit = new ConsoleEmitter(null);
        if ($this->seededConsoleRunId !== null) {
            $this->bindConsoleRunId($this->seededConsoleRunId);
            $emit = $this->beginConsoleAction();
        }

        try {
            $exporter->export($backup, $emit);

            $this->pruneOlderBackups($db, $exporter);

            $fresh = $backup->fresh();

            $user = $backup->user;
            if ($user instanceof User) {
                $auditLogger->record($server, ServerDatabaseAuditEvent::EVENT_BACKUP_EXPORTED, [
                    'server_database_id' => $db->id,
                    'backup_id' => $backup->id,
                    'bytes' => $fresh->bytes,
                    'storage_kind' => $fresh->storage_kind,
                ], $user);
            }

            $actor = $backup->user instanceof User ? $backup->user : null;
            $notifications->notify($server, 'completed', [__('Database — :name', ['name' => $db->name])], $actor, [
                'backup_type' => 'database',
                'backup_id' => (string) $backup->id,
                'database_id' => (string) $db->id,
                'bytes' => $fresh->bytes,
            ]);

            $emit->success(__('Database backup complete — :size', ['size' => Number::fileSize((int) $fresh->bytes)]), 'db');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $backup->update([
                'status' => ServerDatabaseBackup::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $actor = $backup->user instanceof User ? $backup->user : null;
            $notifications->notify($server, 'failed', [__('Database — :name', ['name' => $db->name])], $actor, [
                    'backup_type' => 'database',
                    'backup_id' => (string) $backup->id,
                    'database_id' => (string) $db->id,
                    'error' => $e->getMessage(),
            ]);

            // Keep the historical no-retry behavior: surface the failure on the
            // row + banner, but do NOT re-throw (no queue retry, no duplicate
            // artifacts), matching what scheduled backups have always done.
            $emit->error($e->getMessage(), 'db');
            $this->failConsoleAction($e->getMessage());
        }
    }

    /**
     * Keep only the most-recent N completed backups for this database; delete older files + rows.
     * Failed/pending backups are not pruned — operators can still see their error trail.
     */
    protected function pruneOlderBackups(ServerDatabase $db, DatabaseBackupExporter $exporter): void
    {
        $keep = max(1, (int) config('server_database.backup_retention_per_database', 10));

        $stale = ServerDatabaseBackup::query()
            ->where('server_database_id', $db->id)
            ->where('status', ServerDatabaseBackup::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->skip($keep)
            ->take(1000)
            ->get();

        foreach ($stale as $old) {
            $exporter->deleteArtifact($old);
            $old->delete();
        }
    }
}
