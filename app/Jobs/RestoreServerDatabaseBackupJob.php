<?php

namespace App\Jobs;

use App\Models\ServerDatabaseBackup;
use App\Services\Servers\ServerDatabaseBackupRestorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Restore a database backup into a target DB (W3) — the queued wrapper around
 * {@see ServerDatabaseBackupRestorer}. DESTRUCTIVE; only dispatched after an
 * explicit operator confirmation (see dply:db:restore).
 */
class RestoreServerDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $backupId,
        public ?string $targetDatabase = null,
        public ?string $userId = null,
    ) {}

    public function handle(ServerDatabaseBackupRestorer $restorer): void
    {
        $backup = ServerDatabaseBackup::query()->find($this->backupId);
        if ($backup === null) {
            return;
        }

        try {
            $restorer->restore($backup, $this->targetDatabase);
        } catch (\Throwable $e) {
            Log::error('RestoreServerDatabaseBackupJob failed', [
                'backup_id' => $this->backupId,
                'target' => $this->targetDatabase,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
