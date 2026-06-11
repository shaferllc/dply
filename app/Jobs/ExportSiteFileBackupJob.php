<?php

namespace App\Jobs;

use App\Models\SiteFileBackup;
use App\Services\Notifications\ServerBackupNotificationDispatcher;
use App\Services\Servers\SiteFileBackupExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportSiteFileBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(
        public string $backupId
    ) {
        $q = config('site_file_backup.export_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
        $this->timeout = (int) config('site_file_backup.timeout_seconds', 7200);
    }

    public function handle(SiteFileBackupExporter $exporter, ServerBackupNotificationDispatcher $notifications): void
    {
        $backup = SiteFileBackup::query()->with(['site.server'])->find($this->backupId);
        if (! $backup) {
            return;
        }

        $site = $backup->site;
        if (! $site) {
            return;
        }

        $server = $site->server;
        if (! $server || ! $site->supportsSshFileArchive()) {
            $backup->update([
                'status' => SiteFileBackup::STATUS_FAILED,
                'error_message' => __('This site cannot export files over SSH (runtime or server not ready).'),
            ]);

            if ($server) {
                $notifications->notify($server, 'failed', [__('Site files — :name', ['name' => $site->name])], $backup->user, [
                    'backup_type' => 'site_files',
                    'backup_id' => (string) $backup->id,
                    'site_id' => (string) $site->id,
                    'error' => 'unsupported',
                ]);
            }

            return;
        }

        try {
            // Writes the tar to a durable path on the site's own server, records
            // remote_path + bytes on the row, and prunes the per-server tree.
            $exporter->export($backup);
            $backup->refresh();

            $notifications->notify($server, 'completed', [__('Site files — :name', ['name' => $site->name])], $backup->user, [
                'backup_type' => 'site_files',
                'backup_id' => (string) $backup->id,
                'site_id' => (string) $site->id,
                'bytes' => $backup->bytes,
            ]);
        } catch (\Throwable $e) {
            $backup->update([
                'status' => SiteFileBackup::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $notifications->notify($server, 'failed', [__('Site files — :name', ['name' => $site->name])], $backup->user, [
                'backup_type' => 'site_files',
                'backup_id' => (string) $backup->id,
                'site_id' => (string) $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
