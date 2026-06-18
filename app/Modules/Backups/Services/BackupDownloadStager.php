<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Models\BackupDownloadStaging;
use App\Models\Server;
use App\Models\ServerDatabaseBackup;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use App\Services\Servers\ServerDatabaseRemoteExec;
use App\Support\Servers\DatabaseBackupSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies a backup's durable artifact into the global Hetzner download-staging
 * bucket (or, for backups that are already presignable S3 objects, records a
 * "direct" passthrough), so the browser can be redirected to a one-time
 * presigned GET from any control-plane box. See config/backup_staging.php.
 */
final class BackupDownloadStager
{
    public function __construct(
        private readonly BackupStagingS3ClientFactory $staging,
        private readonly ServerDatabaseRemoteExec $remoteExec,
        private readonly DatabaseBackupExporter $dbExporter,
    ) {}

    /**
     * Resolve the durable source and prepare the staging row → ready | failed.
     * Never throws; failures land on the row for the polling UI.
     */
    public function stage(BackupDownloadStaging $row): void
    {
        $backupable = $row->backupable;
        if ($backupable === null) {
            $this->fail($row, __('This backup no longer exists.'));

            return;
        }

        try {
            if ($backupable instanceof ServerDatabaseBackup) {
                $this->stageDatabaseBackup($row, $backupable);
            } elseif ($backupable instanceof SiteFileBackup) {
                $this->stageSiteFileBackup($row, $backupable);
            } else {
                $this->fail($row, __('This backup type cannot be downloaded.'));
            }
        } catch (\Throwable $e) {
            $this->fail($row, $e->getMessage());
        }
    }

    /**
     * The presigned URL the browser is redirected to. For direct (org-S3) rows
     * this delegates to the existing exporter (incl. cold-storage thaw); for
     * Hetzner rows it presigns the staged object.
     */
    public function presignedGet(BackupDownloadStaging $row): string
    {
        if ($row->mode === BackupDownloadStaging::MODE_DIRECT) {
            $backup = $row->backupable;
            if (! $backup instanceof ServerDatabaseBackup) {
                throw new \RuntimeException(__('Backup is not available for download.'));
            }

            $target = $this->dbExporter->downloadTarget($backup);
            if (($target['mode']) !== 'redirect' || ! isset($target['url'])) {
                throw new \RuntimeException(__('Backup is not available for direct download.'));
            }

            return (string) $target['url'];
        }

        if (! filled($row->bucket) || ! filled($row->object_key)) {
            throw new \RuntimeException(__('Staged download is missing its object.'));
        }

        $s3 = $this->staging->make();
        $request = $s3['client']->createPresignedRequest(
            $s3['client']->getCommand('GetObject', [
                'Bucket' => $row->bucket,
                'Key' => $row->object_key,
            ]),
            '+'.(int) config('backup_staging.presign_get_minutes', 240).' minutes',
        );

        return (string) $request->getUri();
    }

    /**
     * Best-effort deletion of the staged Hetzner object (no-op for direct rows).
     */
    public function deleteStaged(BackupDownloadStaging $row): void
    {
        if ($row->mode !== BackupDownloadStaging::MODE_HETZNER || ! filled($row->object_key)) {
            return;
        }

        try {
            $s3 = $this->staging->make();
            $s3['client']->deleteObject([
                'Bucket' => $row->bucket,
                'Key' => $row->object_key,
            ]);
        } catch (\Throwable) {
            // Row removal still proceeds; an orphaned object is cheap and rare.
        }
    }

    private function stageDatabaseBackup(BackupDownloadStaging $row, ServerDatabaseBackup $backup): void
    {
        if (! $this->dbExporter->isDownloadable($backup)) {
            $this->fail($row, __('This backup is not ready yet.'));

            return;
        }

        $ext = $backup->serverDatabase?->engine === 'sqlite' ? 'db' : 'sql';
        $contentType = $ext === 'db' ? 'application/x-sqlite3' : 'application/sql';

        match ($backup->storage_kind) {
            DatabaseBackupSettings::KIND_DESTINATION => $this->markDirect($row),
            DatabaseBackupSettings::KIND_CONTROL_PLANE => $this->uploadFromControlPlane($row, (string) $backup->disk_path, 'database', $ext, $contentType),
            default => $this->uploadFromServer(
                $row,
                $this->requireServer($backup->serverDatabase?->server, __('The database server is unavailable.')),
                (string) $backup->remote_path,
                'database',
                $ext,
                $contentType,
            ),
        };
    }

    private function stageSiteFileBackup(BackupDownloadStaging $row, SiteFileBackup $backup): void
    {
        if (! $backup->isDownloadable()) {
            $this->fail($row, __('This backup is not ready yet.'));

            return;
        }

        $contentType = 'application/gzip';

        if ($backup->effectiveStorageKind() === SiteFileBackup::STORAGE_KIND_REMOTE_SERVER) {
            $this->uploadFromServer(
                $row,
                $this->requireServer($backup->site->server, __('The site server is unavailable.')),
                (string) $backup->remote_path,
                'site-files',
                'tar.gz',
                $contentType,
            );

            return;
        }

        $this->uploadFromControlPlane($row, (string) $backup->disk_path, 'site-files', 'tar.gz', $contentType);
    }

    /**
     * Presign a PUT on the staging bucket and curl-upload the durable file from
     * the SOURCE server (so the control plane never holds the bytes). Mirrors
     * DatabaseBackupExporter::exportToDestination, minus the `&& rm` — the durable
     * copy must stay.
     */
    private function uploadFromServer(
        BackupDownloadStaging $row,
        Server $server,
        string $remotePath,
        string $kindSlug,
        string $ext,
        string $contentType,
    ): void {
        if ($remotePath === '') {
            $this->fail($row, __('Backup file path is missing.'));

            return;
        }

        $s3 = $this->staging->make();
        $key = $this->objectKey($s3['key_prefix'], $row, $kindSlug, $ext);

        $putRequest = $s3['client']->createPresignedRequest(
            $s3['client']->getCommand('PutObject', [
                'Bucket' => $s3['bucket'],
                'Key' => $key,
                'ContentType' => $contentType,
            ]),
            '+'.(int) config('backup_staging.presign_put_minutes', 30).' minutes',
        );
        $presignedUrl = (string) $putRequest->getUri();

        $uploadCmd = sprintf(
            'curl --silent --show-error --fail-with-body --request PUT --upload-file %s --header %s %s',
            escapeshellarg($remotePath),
            escapeshellarg('Content-Type: '.$contentType),
            escapeshellarg($presignedUrl),
        );

        [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $uploadCmd, 3600);

        if ($exit !== null && $exit !== 0) {
            $this->fail($row, __('Upload to download storage failed: :err', ['err' => Str::limit(trim($out), 600)]));

            return;
        }

        $this->markReady($row, $s3['bucket'], $key);
    }

    /**
     * Control-plane local-disk source (dev only, or legacy site-file rows). Only
     * works on the box that holds the file — otherwise surface a clear re-run hint.
     */
    private function uploadFromControlPlane(
        BackupDownloadStaging $row,
        string $diskPath,
        string $kindSlug,
        string $ext,
        string $contentType,
    ): void {
        $disk = Storage::disk('local');
        if ($diskPath === '' || ! $disk->exists($diskPath)) {
            $this->fail($row, __('This backup is stored on the control plane and isn’t downloadable across boxes — re-run the backup to download it.'));

            return;
        }

        $s3 = $this->staging->make();
        $key = $this->objectKey($s3['key_prefix'], $row, $kindSlug, $ext);

        $s3['client']->putObject([
            'Bucket' => $s3['bucket'],
            'Key' => $key,
            'SourceFile' => $disk->path($diskPath),
            'ContentType' => $contentType,
        ]);

        $this->markReady($row, $s3['bucket'], $key);
    }

    private function requireServer(?Server $server, string $message): Server
    {
        if ($server === null) {
            throw new \RuntimeException($message);
        }

        return $server;
    }

    /** Deterministic key so re-staging overwrites rather than orphaning. */
    private function objectKey(string $prefix, BackupDownloadStaging $row, string $kindSlug, string $ext): string
    {
        $segments = array_filter([
            trim($prefix, '/'),
            $kindSlug,
            (string) $row->backupable_id.'.'.$ext,
        ], static fn (string $s): bool => $s !== '');

        return implode('/', $segments);
    }

    private function markReady(BackupDownloadStaging $row, string $bucket, string $key): void
    {
        $row->update([
            'status' => BackupDownloadStaging::STATUS_READY,
            'mode' => BackupDownloadStaging::MODE_HETZNER,
            'bucket' => $bucket,
            'object_key' => $key,
            'error_message' => null,
            'expires_at' => now()->addMinutes((int) config('backup_staging.ttl_minutes', 240)),
        ]);
    }

    private function markDirect(BackupDownloadStaging $row): void
    {
        $row->update([
            'status' => BackupDownloadStaging::STATUS_READY,
            'mode' => BackupDownloadStaging::MODE_DIRECT,
            'bucket' => null,
            'object_key' => null,
            'error_message' => null,
            'expires_at' => now()->addMinutes((int) config('backup_staging.ttl_minutes', 240)),
        ]);
    }

    private function fail(BackupDownloadStaging $row, string $message): void
    {
        $row->update([
            'status' => BackupDownloadStaging::STATUS_FAILED,
            'error_message' => $message,
        ]);
    }
}
