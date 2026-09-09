<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Models\BackupConfiguration;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerDatabaseRemoteExec;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ships a file that already exists on a server to a backup destination.
 *
 * DatabaseBackupExporter grew this logic inline, interleaved with dumping, so
 * it could only ever serve database dumps — site file archives had nowhere to
 * go and stayed on the box that produced them. The upload half is not specific
 * to dumps: given a path on a server and a destination, the three transports
 * behave identically whatever produced the bytes.
 *
 *   S3 / Spaces          — presigned PUT, curl'd from the server
 *   SFTP / FTP / Rclone  — client binary on the server, credentials in a
 *                          mode-600 file that is removed afterwards
 *   Dropbox / Drive      — bearer token minted in the control plane and spent
 *                          by a script on the server
 *
 * Returns the provider-side handle to record on the backup row: a POSIX path
 * for everything except Google Drive, which assigns an opaque file id at upload
 * time. The source file is left in place — the caller owns its lifecycle.
 */
final class BackupArtifactUploader
{
    /** Presigned PUT lifetime. Long enough for a slow upload of a large archive. */
    private const PRESIGNED_PUT_TTL_MINUTES = 180;

    public function __construct(
        private readonly ServerDatabaseRemoteExec $remoteExec,
        private readonly DatabaseBackupS3ClientFactory $s3Factory,
        private readonly FileTransportCommandFactory $transport,
        private readonly CloudApiCommandFactory $cloudApi,
        private readonly CloudApiTokenResolver $cloudTokens,
    ) {}

    /**
     * @param  string  $sourcePath   absolute path of the artifact on $server
     * @param  string  $key          destination-relative key (no leading slash)
     * @param  string  $correlationId used to name the temp credential files
     * @return string the handle to store in `destination_path`
     */
    public function upload(
        Server $server,
        BackupConfiguration $configuration,
        string $sourcePath,
        string $key,
        string $correlationId,
        string $contentType = 'application/gzip',
        ?ConsoleEmitter $emit = null,
        string $stepKey = 'files',
    ): string {
        $emit ??= new ConsoleEmitter(null);
        $label = BackupConfiguration::labelForProvider($configuration->provider);
        $emit->step($stepKey, __('Uploading to :label …', ['label' => $label]));

        if (FileTransportCommandFactory::supports($configuration->provider)) {
            return $this->viaFileTransport($server, $configuration, $sourcePath, $key, $correlationId, $label);
        }

        if (CloudApiCommandFactory::supports($configuration->provider)) {
            return $this->viaCloudApi($server, $configuration, $sourcePath, $key, $correlationId, $label);
        }

        return $this->viaPresignedPut($server, $configuration, $sourcePath, $key, $contentType);
    }

    private function viaFileTransport(
        Server $server,
        BackupConfiguration $configuration,
        string $sourcePath,
        string $key,
        string $correlationId,
        string $label,
    ): string {
        $objectPath = $this->transport->objectPath($configuration, $key);
        $upload = $this->transport->uploadCommand($configuration, $sourcePath, $objectPath, $correlationId);
        $files = $this->transport->withKeyFile($configuration, $upload['files'], $correlationId);

        try {
            $this->placeFiles($server, $files);

            [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $upload['command'], 3600);

            if ($exit !== null && $exit !== 0) {
                throw new RuntimeException($label.' upload failed: '.Str::limit(trim($out), 800));
            }
        } finally {
            // Credentials never outlive the upload, even on failure.
            $cleanup = $this->transport->cleanupCommand($files);
            if ($cleanup !== '') {
                $this->remoteExec->shellRunWithExit($server, $cleanup, 60);
            }
        }

        return $objectPath;
    }

    private function viaCloudApi(
        Server $server,
        BackupConfiguration $configuration,
        string $sourcePath,
        string $key,
        string $correlationId,
        string $label,
    ): string {
        // Resolved here rather than by the caller: a slow archive shouldn't burn
        // the token's lifetime before the upload starts.
        $token = $this->cloudTokens->forConfiguration($configuration);

        $objectPath = $this->cloudApi->objectPath($configuration, $key);
        $upload = $this->cloudApi->uploadCommand($configuration, $token, $sourcePath, $objectPath, $correlationId);

        try {
            $this->placeFiles($server, $upload['files']);

            [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $upload['command'], 3600);

            if ($exit !== null && $exit !== 0) {
                throw new RuntimeException($label.' upload failed: '.Str::limit(trim($out), 800));
            }

            // Drive answers with the file id — the only handle a later download
            // or prune can use. Dropbox echoes the path back unchanged.
            return $this->cloudApi->handleFromUploadOutput($configuration, $out, $objectPath);
        } finally {
            $cleanup = $this->cloudApi->cleanupCommand($upload['files']);
            if ($cleanup !== '') {
                $this->remoteExec->shellRunWithExit($server, $cleanup, 60);
            }
        }
    }

    private function viaPresignedPut(
        Server $server,
        BackupConfiguration $configuration,
        string $sourcePath,
        string $key,
        string $contentType,
    ): string {
        $s3 = $this->s3Factory->forConfiguration($configuration);
        $objectKey = trim((string) $s3['key_prefix'], '/') !== ''
            ? trim((string) $s3['key_prefix'], '/').'/'.ltrim($key, '/')
            : ltrim($key, '/');

        // AWS-only per-object storage class (cold tiers). DO Spaces cold is a
        // bucket-level tier, so only AWS sets this.
        $storageClass = $configuration->provider === BackupConfiguration::PROVIDER_AWS_S3
            ? trim((string) ($configuration->config['storage_class'] ?? ''))
            : '';
        $applyClass = $storageClass !== '' && $storageClass !== 'STANDARD';

        $putParams = [
            'Bucket' => $s3['bucket'],
            'Key' => $objectKey,
            'ContentType' => $contentType,
        ];
        if ($applyClass) {
            $putParams['StorageClass'] = $storageClass;
        }

        $presignedUrl = (string) $s3['client']->createPresignedRequest(
            $s3['client']->getCommand('PutObject', $putParams),
            '+'.self::PRESIGNED_PUT_TTL_MINUTES.' minutes',
        )->getUri();

        // x-amz-storage-class is a signed header, so curl must send the exact
        // same value or the signature won't match.
        $storageClassHeader = $applyClass
            ? ' --header '.escapeshellarg('x-amz-storage-class: '.$storageClass)
            : '';

        $command = sprintf(
            'curl --silent --show-error --fail-with-body --request PUT --upload-file %s --header %s%s %s',
            escapeshellarg($sourcePath),
            escapeshellarg('Content-Type: '.$contentType),
            $storageClassHeader,
            escapeshellarg($presignedUrl),
        );

        [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $command, 3600);

        if ($exit !== null && $exit !== 0) {
            throw new RuntimeException('S3 upload failed: '.Str::limit(trim($out), 800));
        }

        return $objectKey;
    }

    /**
     * Pull a previously uploaded artifact back onto the server and return its
     * local path, so an existing remote-file streamer can serve it.
     *
     * None of these transports can be presigned for a browser to fetch
     * directly (and for S3 the caller should presign a GET instead of coming
     * here), so a download is a two-hop trip rather than a redirect.
     */
    public function stageOnServer(
        Server $server,
        BackupConfiguration $configuration,
        string $handle,
        string $localPath,
        string $correlationId,
    ): string {
        if (CloudApiCommandFactory::supports($configuration->provider)) {
            $token = $this->cloudTokens->forConfiguration($configuration);
            $download = $this->cloudApi->downloadCommand($configuration, $token, $handle, $localPath, $correlationId);

            try {
                $this->placeFiles($server, $download['files']);
                [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $download['command'], 3600);

                if ($exit !== null && $exit !== 0) {
                    throw new RuntimeException('Fetch failed: '.Str::limit(trim($out), 800));
                }
            } finally {
                $cleanup = $this->cloudApi->cleanupCommand($download['files']);
                if ($cleanup !== '') {
                    $this->remoteExec->shellRunWithExit($server, $cleanup, 60);
                }
            }

            return $localPath;
        }

        if (! FileTransportCommandFactory::supports($configuration->provider)) {
            throw new RuntimeException(__('This destination type cannot be staged back to the server.'));
        }

        $download = $this->transport->downloadCommand($configuration, $handle, $localPath, $correlationId);
        $files = $this->transport->withKeyFile($configuration, $download['files'], $correlationId);

        try {
            $this->placeFiles($server, $files);
            [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $download['command'], 3600);

            if ($exit !== null && $exit !== 0) {
                throw new RuntimeException('Fetch failed: '.Str::limit(trim($out), 800));
            }
        } finally {
            $cleanup = $this->transport->cleanupCommand($files);
            if ($cleanup !== '') {
                $this->remoteExec->shellRunWithExit($server, $cleanup, 60);
            }
        }

        return $localPath;
    }

    /** Presigned GET for S3-compatible destinations — a plain redirect target. */
    public function presignedGetUrl(BackupConfiguration $configuration, string $key, int $ttlMinutes = 15): string
    {
        $s3 = $this->s3Factory->forConfiguration($configuration);

        return (string) $s3['client']->createPresignedRequest(
            $s3['client']->getCommand('GetObject', ['Bucket' => $s3['bucket'], 'Key' => $key]),
            '+'.$ttlMinutes.' minutes',
        )->getUri();
    }

    /**
     * Best-effort removal of a previously uploaded artifact.
     *
     * Failures are swallowed: deleting the row is the operator's actual intent,
     * and a destination that has since had its credentials rotated must not
     * leave an undeletable record behind. Worst case is an orphaned object the
     * destination's own lifecycle rules will age out.
     */
    public function delete(Server $server, BackupConfiguration $configuration, string $handle, string $correlationId): void
    {
        if (trim($handle) === '') {
            return;
        }

        try {
            if (FileTransportCommandFactory::supports($configuration->provider)) {
                $delete = $this->transport->deleteCommand($configuration, $handle, $correlationId);
                $files = $this->transport->withKeyFile($configuration, $delete['files'], $correlationId);

                try {
                    $this->placeFiles($server, $files);
                    $this->remoteExec->shellRunWithExit($server, $delete['command'], 300);
                } finally {
                    $cleanup = $this->transport->cleanupCommand($files);
                    if ($cleanup !== '') {
                        $this->remoteExec->shellRunWithExit($server, $cleanup, 60);
                    }
                }

                return;
            }

            if (CloudApiCommandFactory::supports($configuration->provider)) {
                $token = $this->cloudTokens->forConfiguration($configuration);
                $delete = $this->cloudApi->deleteCommand($configuration, $token, $handle, $correlationId);

                try {
                    $this->placeFiles($server, $delete['files']);
                    $this->remoteExec->shellRunWithExit($server, $delete['command'], 300);
                } finally {
                    $cleanup = $this->cloudApi->cleanupCommand($delete['files']);
                    if ($cleanup !== '') {
                        $this->remoteExec->shellRunWithExit($server, $cleanup, 60);
                    }
                }

                return;
            }

            // S3 deletes from the control plane — no server round trip needed.
            $s3 = $this->s3Factory->forConfiguration($configuration);
            $s3['client']->deleteObject(['Bucket' => $s3['bucket'], 'Key' => $handle]);
        } catch (\Throwable) {
            // See the note above.
        }
    }

    /** @param  array<string, string>  $files */
    private function placeFiles(Server $server, array $files): void
    {
        foreach ($files as $path => $contents) {
            $this->remoteExec->putFile($server, $path, $contents);
            $this->remoteExec->shellRunWithExit($server, 'chmod 600 '.escapeshellarg($path), 30);
        }
    }
}
