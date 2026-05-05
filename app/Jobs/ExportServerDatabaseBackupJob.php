<?php

namespace App\Jobs;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseBackup;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseDumpOutputValidator;
use App\Services\Servers\ServerDatabaseRemoteExec;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

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

    public function handle(ServerDatabaseRemoteExec $remoteExec, ServerDatabaseAuditLogger $auditLogger): void
    {
        $backup = ServerDatabaseBackup::query()->with(['serverDatabase.server'])->find($this->backupId);
        if (! $backup) {
            return;
        }

        $db = $backup->serverDatabase;
        $server = $db->server;

        $diskName = (string) config('server_database.backup_disk', 'local');
        $disk = Storage::disk($diskName);

        try {
            $extension = match ($db->engine) {
                'sqlite' => 'db',
                default => 'sql',
            };

            if ($db->engine === 'sqlite') {
                $maxBytes = (int) config('server_database.sqlite_backup_max_bytes', 256 * 1024 * 1024);
                $contents = $remoteExec->sqliteBackup($server, (string) $db->host, $maxBytes);

                if ($contents === '') {
                    throw new \RuntimeException('SQLite backup produced an empty file.');
                }
            } else {
                $contents = $db->engine === 'postgres'
                    ? $remoteExec->pgDump($server, $db->name, $db->username, $db->password)
                    : $remoteExec->mysqldump($server, $db->name, $db->username, $db->password);

                if (ServerDatabaseDumpOutputValidator::looksLikeFailedDump($db->engine, $contents)) {
                    throw new \RuntimeException('Dump command failed: '.substr($contents, 0, 1200));
                }
            }

            $relative = 'database-backups/'.$server->id.'/'.$backup->id.'.'.$extension;
            $disk->put($relative, $contents);

            $backup->update([
                'status' => ServerDatabaseBackup::STATUS_COMPLETED,
                'disk_path' => $relative,
                'bytes' => strlen($contents),
            ]);

            $this->pruneOlderBackups($db, $disk);

            $user = $backup->user;
            if ($user) {
                $auditLogger->record($server, ServerDatabaseAuditEvent::EVENT_BACKUP_EXPORTED, [
                    'server_database_id' => $db->id,
                    'backup_id' => $backup->id,
                    'bytes' => strlen($contents),
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
    protected function pruneOlderBackups(ServerDatabase $db, Filesystem $disk): void
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
            if (! empty($old->disk_path)) {
                try {
                    $disk->delete($old->disk_path);
                } catch (\Throwable) {
                    // Disk-side delete failures shouldn't block pruning the row;
                    // a future prune will retry the disk delete.
                }
            }
            $old->delete();
        }
    }
}
