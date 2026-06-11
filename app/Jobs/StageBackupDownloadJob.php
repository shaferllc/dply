<?php

namespace App\Jobs;

use App\Models\BackupDownloadStaging;
use App\Services\Backups\BackupDownloadStager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies a backup's durable artifact into the Hetzner download-staging bucket so
 * the browser can be redirected to a presigned GET. SSH (the curl-upload runs on
 * the source server) must never run inline in an HTTP request, so this is queued;
 * the UI polls the staging row for ready/failed. Targets an optional dedicated
 * queue so the upload runs on a box that can reach customer servers.
 */
class StageBackupDownloadJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public string $stagingId,
    ) {
        $q = config('backup_staging.upload_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(BackupDownloadStager $stager): void
    {
        $row = BackupDownloadStaging::query()->with('backupable')->find($this->stagingId);
        if (! $row) {
            return;
        }

        try {
            $stager->stage($row);
        } catch (\Throwable $e) {
            $row->update([
                'status' => BackupDownloadStaging::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
