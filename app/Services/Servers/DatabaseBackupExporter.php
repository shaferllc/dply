<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\BackupConfiguration;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Support\Servers\DatabaseBackupSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists database export jobs to the customer server, an org S3 destination,
 * or (dev-only) the Dply control-plane disk.
 */
final class DatabaseBackupExporter
{
    public const PRESIGNED_PUT_TTL_MINUTES = 30;

    public const PRESIGNED_GET_TTL_MINUTES = 30;

    public function __construct(
        private readonly ServerDatabaseRemoteExec $remoteExec,
        private readonly ServerDatabaseProvisioner $provisioner,
        private readonly DatabaseBackupS3ClientFactory $s3Factory,
    ) {}

    public function export(ServerDatabaseBackup $backup): void
    {
        $backup->loadMissing(['serverDatabase.server.organization']);
        $db = $backup->serverDatabase;
        if ($db === null) {
            throw new \RuntimeException('Database backup has no server database.');
        }

        $server = $db->server;
        $settings = DatabaseBackupSettings::fromServer($server);
        $kind = $backup->storage_kind ?: $settings->resolveKind($backup->backup_configuration_id);
        $extension = $db->engine === 'sqlite' ? 'db' : 'sql';

        match ($kind) {
            DatabaseBackupSettings::KIND_DESTINATION => $this->exportToDestination($backup, $db, $server, $extension),
            DatabaseBackupSettings::KIND_CONTROL_PLANE => $this->exportToControlPlane($backup, $db, $server, $extension),
            default => $this->exportToRemoteServer($backup, $db, $server, $extension, $settings),
        };
    }

    public function deleteArtifact(ServerDatabaseBackup $backup): void
    {
        $backup->loadMissing('serverDatabase.server');

        match ($backup->storage_kind) {
            DatabaseBackupSettings::KIND_DESTINATION => $this->deleteS3Object($backup),
            DatabaseBackupSettings::KIND_CONTROL_PLANE => $this->deleteControlPlaneFile($backup),
            default => $this->deleteRemoteFile($backup),
        };
    }

    public function isDownloadable(ServerDatabaseBackup $backup): bool
    {
        if ($backup->status !== ServerDatabaseBackup::STATUS_COMPLETED) {
            return false;
        }

        return match ($backup->storage_kind) {
            DatabaseBackupSettings::KIND_DESTINATION => filled($backup->s3_bucket) && filled($backup->s3_key),
            DatabaseBackupSettings::KIND_CONTROL_PLANE => filled($backup->disk_path),
            default => filled($backup->remote_path),
        };
    }

    /**
     * @return array{mode: string, url?: string, remote_path?: string, disk_path?: string}
     */
    public function downloadTarget(ServerDatabaseBackup $backup): array
    {
        if (! $this->isDownloadable($backup)) {
            throw new \RuntimeException(__('Backup is not ready yet.'));
        }

        return match ($backup->storage_kind) {
            DatabaseBackupSettings::KIND_DESTINATION => [
                'mode' => 'redirect',
                'url' => $this->presignedGetUrl($backup),
            ],
            DatabaseBackupSettings::KIND_CONTROL_PLANE => [
                'mode' => 'disk',
                'disk_path' => $backup->disk_path,
            ],
            default => [
                'mode' => 'remote',
                'remote_path' => $backup->remote_path,
            ],
        };
    }

    public function prepareBackupRow(
        ServerDatabaseBackup $backup,
        Server $server,
        ?string $explicitBackupConfigurationId = null,
    ): void {
        $settings = DatabaseBackupSettings::fromServer($server);
        $configId = $settings->resolveBackupConfigurationId($explicitBackupConfigurationId);
        $kind = $settings->resolveKind($configId);

        $backup->storage_kind = $kind;
        $backup->backup_configuration_id = $configId;
        $backup->save();
    }

    private function exportToRemoteServer(
        ServerDatabaseBackup $backup,
        ServerDatabase $db,
        Server $server,
        string $extension,
        DatabaseBackupSettings $settings,
    ): void {
        $remotePath = $this->remotePath($server, $db, $backup, $extension);
        $bytes = $this->writeDumpToRemotePath($db, $server, $remotePath, $extension);

        if ($bytes <= 0) {
            throw new \RuntimeException('Backup produced an empty file.');
        }

        $serverTree = rtrim((string) config('server_database.remote_backup_root', '/var/lib/dply/database-backups'), '/')
            .'/'.$server->id;
        $this->remoteExec->pruneRemoteBackupTree($server, $serverTree, $settings->remoteMaxBytes());

        $backup->update([
            'status' => ServerDatabaseBackup::STATUS_COMPLETED,
            'storage_kind' => DatabaseBackupSettings::KIND_REMOTE_SERVER,
            'remote_path' => $remotePath,
            'disk_path' => null,
            's3_bucket' => null,
            's3_key' => null,
            'bytes' => $bytes,
        ]);
    }

    private function exportToDestination(
        ServerDatabaseBackup $backup,
        ServerDatabase $db,
        Server $server,
        string $extension,
    ): void {
        $configuration = $this->resolveDestinationConfiguration($backup, $server);
        $s3 = $this->s3Factory->forConfiguration($configuration);

        $tempPath = '/tmp/dply-db-export-'.$backup->id.'.'.$extension;
        $bytes = $this->writeDumpToRemotePath($db, $server, $tempPath, $extension);

        if ($bytes <= 0) {
            $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg('rm -f '.escapeshellarg($tempPath)), 30);

            throw new \RuntimeException('Backup produced an empty file.');
        }

        $key = $this->buildObjectKey($s3['key_prefix'], $server, $db, $backup, $extension);
        $contentType = $extension === 'db' ? 'application/x-sqlite3' : 'application/sql';

        $putRequest = $s3['client']->createPresignedRequest(
            $s3['client']->getCommand('PutObject', [
                'Bucket' => $s3['bucket'],
                'Key' => $key,
                'ContentType' => $contentType,
            ]),
            '+'.self::PRESIGNED_PUT_TTL_MINUTES.' minutes',
        );
        $presignedUrl = (string) $putRequest->getUri();

        $uploadCmd = sprintf(
            'curl --silent --show-error --fail-with-body --request PUT --upload-file %s --header %s %s && rm -f %s',
            escapeshellarg($tempPath),
            escapeshellarg('Content-Type: '.$contentType),
            escapeshellarg($presignedUrl),
            escapeshellarg($tempPath),
        );

        [$out, $exit] = $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg($uploadCmd), 3600);

        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('S3 upload failed: '.Str::limit(trim($out), 800));
        }

        $backup->update([
            'status' => ServerDatabaseBackup::STATUS_COMPLETED,
            'storage_kind' => DatabaseBackupSettings::KIND_DESTINATION,
            'remote_path' => null,
            'disk_path' => null,
            's3_bucket' => $s3['bucket'],
            's3_key' => $key,
            'bytes' => $bytes,
        ]);
    }

    private function exportToControlPlane(
        ServerDatabaseBackup $backup,
        ServerDatabase $db,
        Server $server,
        string $extension,
    ): void {
        if (! config('server_database.allow_control_plane_storage', false)) {
            throw new \RuntimeException('Control-plane database backup storage is disabled.');
        }

        $contents = $this->dumpToString($db, $server, $extension);
        $diskName = (string) config('server_database.backup_disk', 'local');
        $relative = 'database-backups/'.$server->id.'/'.$backup->id.'.'.$extension;
        Storage::disk($diskName)->put($relative, $contents);

        $backup->update([
            'status' => ServerDatabaseBackup::STATUS_COMPLETED,
            'storage_kind' => DatabaseBackupSettings::KIND_CONTROL_PLANE,
            'disk_path' => $relative,
            'remote_path' => null,
            's3_bucket' => null,
            's3_key' => null,
            'bytes' => strlen($contents),
        ]);
    }

    private function writeDumpToRemotePath(ServerDatabase $db, Server $server, string $remotePath, string $extension): int
    {
        if ($db->engine === 'sqlite') {
            $maxBytes = (int) config('server_database.sqlite_backup_max_bytes', 256 * 1024 * 1024);
            $source = $this->provisioner->resolvedSqlitePath($db);

            return $this->remoteExec->sqliteBackupToPath($server, $source, $remotePath, $maxBytes);
        }

        if ($db->engine === 'postgres') {
            $bytes = $this->remoteExec->pgDumpToPath($server, $db->name, $db->username, $db->password, $remotePath);
            $this->assertRemoteDumpLooksValid($server, $remotePath, 'postgres');

            return $bytes;
        }

        if ($db->engine === 'mongodb') {
            throw new \RuntimeException(__('MongoDB backup export to server path is not supported yet — use on-server mongodump from Advanced.'));
        }

        if ($db->engine === 'clickhouse') {
            throw new \RuntimeException(__('ClickHouse backups are not supported in this workspace yet.'));
        }

        $bytes = $this->remoteExec->mysqldumpToPath($server, $db->name, $db->username, $db->password, $remotePath);
        $this->assertRemoteDumpLooksValid($server, $remotePath, 'mysql');

        return $bytes;
    }

    private function dumpToString(ServerDatabase $db, Server $server, string $extension): string
    {
        if ($db->engine === 'sqlite') {
            $maxBytes = (int) config('server_database.sqlite_backup_max_bytes', 256 * 1024 * 1024);

            return $this->remoteExec->sqliteBackup($server, $this->provisioner->resolvedSqlitePath($db), $maxBytes);
        }

        $contents = match ($db->engine) {
            'postgres' => $this->remoteExec->pgDump($server, $db->name, $db->username, $db->password),
            'mongodb' => $this->remoteExec->mongodump($server, $db->name, $db->username, $db->password),
            'clickhouse' => throw new \RuntimeException(__('ClickHouse backups are not supported in this workspace yet.')),
            default => $this->remoteExec->mysqldump($server, $db->name, $db->username, $db->password),
        };

        if (ServerDatabaseDumpOutputValidator::looksLikeFailedDump($db->engine, $contents)) {
            throw new \RuntimeException('Dump command failed: '.substr($contents, 0, 1200));
        }

        if ($contents === '') {
            throw new \RuntimeException('Backup produced an empty file.');
        }

        return $contents;
    }

    private function assertRemoteDumpLooksValid(Server $server, string $remotePath, string $engine): void
    {
        $headCmd = 'head -c 1200 '.escapeshellarg($remotePath).' 2>/dev/null || true';
        [$head] = $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg($headCmd), 60);

        if (ServerDatabaseDumpOutputValidator::looksLikeFailedDump($engine, $head)) {
            $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg('rm -f '.escapeshellarg($remotePath)), 30);

            throw new \RuntimeException('Dump command failed: '.Str::limit(trim($head), 800));
        }
    }

    private function remotePath(Server $server, ServerDatabase $db, ServerDatabaseBackup $backup, string $extension): string
    {
        $root = rtrim((string) config('server_database.remote_backup_root', '/var/lib/dply/database-backups'), '/');

        return $root.'/'.$server->id.'/'.$db->id.'/'.$backup->id.'.'.$extension;
    }

    private function buildObjectKey(string $prefix, Server $server, ServerDatabase $db, ServerDatabaseBackup $backup, string $extension): string
    {
        $segments = array_filter([
            trim($prefix, '/'),
            (string) ($server->organization_id ?? 'no-org'),
            (string) $server->id,
            (string) $db->id,
            $backup->id.'.'.$extension,
        ], fn ($s) => $s !== '');

        return implode('/', $segments);
    }

    private function resolveDestinationConfiguration(ServerDatabaseBackup $backup, Server $server): BackupConfiguration
    {
        $id = $backup->backup_configuration_id;
        if ($id === null || $server->organization_id === null) {
            throw new \RuntimeException('No backup destination configured for this export.');
        }

        $configuration = BackupConfiguration::query()
            ->where('organization_id', $server->organization_id)
            ->whereKey($id)
            ->first();

        if ($configuration === null) {
            throw new \RuntimeException('Backup destination not found for this organization.');
        }

        return $configuration;
    }

    private function presignedGetUrl(ServerDatabaseBackup $backup): string
    {
        $backup->loadMissing('serverDatabase.server.organization', 'backupConfiguration');

        $configuration = $backup->backupConfiguration;
        if ($configuration === null) {
            throw new \RuntimeException('Backup destination record is missing.');
        }

        $s3 = $this->s3Factory->forConfiguration($configuration);

        if ($backup->s3_bucket !== $s3['bucket'] || $backup->s3_key === null) {
            throw new \RuntimeException('Backup S3 location is invalid.');
        }

        $request = $s3['client']->createPresignedRequest(
            $s3['client']->getCommand('GetObject', [
                'Bucket' => $backup->s3_bucket,
                'Key' => $backup->s3_key,
            ]),
            '+'.self::PRESIGNED_GET_TTL_MINUTES.' minutes',
        );

        return (string) $request->getUri();
    }

    private function deleteRemoteFile(ServerDatabaseBackup $backup): void
    {
        $path = $backup->remote_path;
        $server = $backup->serverDatabase?->server;
        if ($path === null || $server === null) {
            return;
        }

        $this->remoteExec->shellRunWithExit(
            $server,
            'bash -lc '.escapeshellarg('rm -f '.escapeshellarg($path)),
            60,
        );
    }

    private function deleteControlPlaneFile(ServerDatabaseBackup $backup): void
    {
        if (empty($backup->disk_path)) {
            return;
        }

        $disk = Storage::disk((string) config('server_database.backup_disk', 'local'));
        if ($disk->exists($backup->disk_path)) {
            $disk->delete($backup->disk_path);
        }
    }

    private function deleteS3Object(ServerDatabaseBackup $backup): void
    {
        if ($backup->s3_bucket === null || $backup->s3_key === null) {
            return;
        }

        $configuration = $backup->backupConfiguration;
        if ($configuration === null) {
            return;
        }

        try {
            $s3 = $this->s3Factory->forConfiguration($configuration);
            $s3['client']->deleteObject([
                'Bucket' => $backup->s3_bucket,
                'Key' => $backup->s3_key,
            ]);
        } catch (\Throwable) {
            // Best-effort delete; row removal still proceeds.
        }
    }
}
