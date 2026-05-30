<?php

namespace App\Jobs;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseBackup;
use App\Services\Servers\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseAuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportServerDatabaseBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public string $backupId
    ) {
        $q = config('server_database.export_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(DatabaseBackupExporter $exporter, ServerDatabaseAuditLogger $auditLogger): void
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

        try {
            $exporter->export($backup);

            $this->pruneOlderBackups($db, $exporter);

            $user = $backup->user;
            if ($user) {
                $auditLogger->record($server, ServerDatabaseAuditEvent::EVENT_BACKUP_EXPORTED, [
                    'server_database_id' => $db->id,
                    'backup_id' => $backup->id,
                    'bytes' => $backup->fresh()?->bytes,
                    'storage_kind' => $backup->fresh()?->storage_kind,
                ], $user);
            }
        } catch (\Throwable $e) {
            $backup->update([
                'status' => ServerDatabaseBackup::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
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
