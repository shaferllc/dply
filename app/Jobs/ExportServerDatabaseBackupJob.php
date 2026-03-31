<?php

namespace App\Jobs;

use App\Models\ServerDatabaseAuditEvent;
use App\Models\ServerDatabaseBackup;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseDumpOutputValidator;
use App\Services\Servers\ServerDatabaseRemoteExec;
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

        try {
            if ($db->engine === 'postgres') {
                $contents = $remoteExec->pgDump($server, $db->name, $db->username, $db->password);
            } else {
                $contents = $remoteExec->mysqldump($server, $db->name, $db->username, $db->password);
            }

            if (ServerDatabaseDumpOutputValidator::looksLikeFailedDump($db->engine, $contents)) {
                throw new \RuntimeException('Dump command failed: '.substr($contents, 0, 1200));
            }

            $relative = 'database-backups/'.$server->id.'/'.$backup->id.'.sql';
            Storage::disk('local')->put($relative, $contents);

            $backup->update([
                'status' => ServerDatabaseBackup::STATUS_COMPLETED,
                'disk_path' => $relative,
                'bytes' => strlen($contents),
            ]);

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
}
