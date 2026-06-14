<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\BackupConfiguration;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Support\Servers\DatabaseBackupSettings;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
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

    /**
     * @param  ConsoleEmitter|null  $emit  optional progress sink for on-demand
     *                                     runs; null (scheduled backups) becomes a no-op emitter so the phase
     *                                     lines below cost nothing and never touch a console row.
     */
    public function export(ServerDatabaseBackup $backup, ?ConsoleEmitter $emit = null): void
    {
        $emit ??= new ConsoleEmitter(null);

        $backup->loadMissing(['serverDatabase.server.organization']);
        $db = $backup->serverDatabase;
        if ($db === null) {
            throw new \RuntimeException('Database backup has no server database.');
        }

        $server = $db->server;
        $settings = DatabaseBackupSettings::fromServer($server);
        $kind = $backup->storage_kind ?: $settings->resolveKind($backup->backup_configuration_id);
        $extension = $db->engine === 'sqlite' ? 'db' : 'sql';

        $emit->step('db', __('Backing up :name (:engine) → :dest', [
            'name' => $db->name,
            'engine' => $db->engine,
            'dest' => $this->destinationLabel($kind),
        ]));

        match ($kind) {
            DatabaseBackupSettings::KIND_DESTINATION => $this->exportToDestination($backup, $db, $server, $extension, $emit),
            DatabaseBackupSettings::KIND_CONTROL_PLANE => $this->exportToControlPlane($backup, $db, $server, $extension, $emit),
            default => $this->exportToRemoteServer($backup, $db, $server, $extension, $settings, $emit),
        };
    }

    /** Human-readable label for the resolved storage kind, used in phase lines. */
    private function destinationLabel(string $kind): string
    {
        return match ($kind) {
            DatabaseBackupSettings::KIND_DESTINATION => __('S3 destination'),
            DatabaseBackupSettings::KIND_CONTROL_PLANE => __('control plane'),
            default => __('the server'),
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

        if ($backup->storage_kind === DatabaseBackupSettings::KIND_DESTINATION) {
            // Cold-storage (Glacier/Deep Archive) objects can't be downloaded
            // until thawed; this requests a restore + throws a status message
            // when the object isn't immediately retrievable.
            $this->ensureDestinationObjectRetrievable($backup);

            return ['mode' => 'redirect', 'url' => $this->presignedGetUrl($backup)];
        }

        return match ($backup->storage_kind) {
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
        ConsoleEmitter $emit,
    ): void {
        $remotePath = $this->remotePath($server, $db, $backup, $extension);
        $emit->step('db', __('Dumping :name …', ['name' => $db->name]));
        $bytes = $this->writeDumpToRemotePath($db, $server, $remotePath, $extension, $emit);

        if ($bytes <= 0) {
            throw new \RuntimeException('Backup produced an empty file.');
        }

        $emit->step('db', __('Dumped :size — pruning old backups on the server …', ['size' => Number::fileSize($bytes)]));

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
        ConsoleEmitter $emit,
    ): void {
        $configuration = $this->resolveDestinationConfiguration($backup, $server);
        $s3 = $this->s3Factory->forConfiguration($configuration);

        $tempPath = '/tmp/dply-db-export-'.$backup->id.'.'.$extension;
        $emit->step('db', __('Dumping :name …', ['name' => $db->name]));
        $bytes = $this->writeDumpToRemotePath($db, $server, $tempPath, $extension, $emit);

        if ($bytes <= 0) {
            $this->remoteExec->shellRunWithExit($server, 'rm -f '.escapeshellarg($tempPath), 30);

            throw new \RuntimeException('Backup produced an empty file.');
        }

        $emit->step('db', __('Dumped :size — uploading to :bucket …', [
            'size' => Number::fileSize($bytes),
            'bucket' => $s3['bucket'],
        ]));

        $key = $this->buildObjectKey($s3['key_prefix'], $server, $db, $backup, $extension);
        $contentType = $extension === 'db' ? 'application/x-sqlite3' : 'application/sql';

        // AWS-only per-object storage class (cold tiers). DO Spaces cold is a
        // bucket-level tier (no per-object class), so only AWS sets this.
        $storageClass = $configuration->provider === BackupConfiguration::PROVIDER_AWS_S3
            ? trim((string) ($configuration->config['storage_class'] ?? ''))
            : '';
        $applyClass = $storageClass !== '' && $storageClass !== 'STANDARD';

        $putParams = [
            'Bucket' => $s3['bucket'],
            'Key' => $key,
            'ContentType' => $contentType,
        ];
        if ($applyClass) {
            $putParams['StorageClass'] = $storageClass;
        }

        $putRequest = $s3['client']->createPresignedRequest(
            $s3['client']->getCommand('PutObject', $putParams),
            '+'.self::PRESIGNED_PUT_TTL_MINUTES.' minutes',
        );
        $presignedUrl = (string) $putRequest->getUri();

        // x-amz-storage-class is a signed header on the presigned PUT, so curl
        // must send the exact same value or the signature won't match.
        $storageClassHeader = $applyClass
            ? ' --header '.escapeshellarg('x-amz-storage-class: '.$storageClass)
            : '';

        $uploadCmd = sprintf(
            'curl --silent --show-error --fail-with-body --request PUT --upload-file %s --header %s%s %s && rm -f %s',
            escapeshellarg($tempPath),
            escapeshellarg('Content-Type: '.$contentType),
            $storageClassHeader,
            escapeshellarg($presignedUrl),
            escapeshellarg($tempPath),
        );

        [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $uploadCmd, 3600);

        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('S3 upload failed: '.Str::limit(trim($out), 800));
        }

        $emit->step('db', __('Uploaded to :bucket — finalizing …', ['bucket' => $s3['bucket']]));

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
        ConsoleEmitter $emit,
    ): void {
        if (! config('server_database.allow_control_plane_storage', false)) {
            throw new \RuntimeException('Control-plane database backup storage is disabled.');
        }

        $emit->step('db', __('Dumping :name …', ['name' => $db->name]));
        $contents = $this->dumpToString($db, $server, $extension, $emit);
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

    private function writeDumpToRemotePath(ServerDatabase $db, Server $server, string $remotePath, string $extension, ConsoleEmitter $emit): int
    {
        if ($db->engine === 'sqlite') {
            $maxBytes = (int) config('server_database.sqlite_backup_max_bytes', 256 * 1024 * 1024);
            $source = $this->provisioner->resolvedSqlitePath($db);

            return $this->remoteExec->sqliteBackupToPath($server, $source, $remotePath, $maxBytes);
        }

        if ($db->engine === 'postgres') {
            // Postgres admin fallback is always usable (sudo -u postgres when no
            // stored superuser credential), so adminAvailable is true.
            $bytes = $this->dumpToPathWithAdminFallback($db, $server, $remotePath, $emit, 'postgres', true,
                fn (): int => $this->remoteExec->pgDumpToPath($server, $db->name, $db->username, $db->password, $remotePath),
                fn (): int => $this->remoteExec->pgDumpAdminToPath($server, $db->name, $remotePath),
            );
            $this->assertRemoteDumpLooksValid($server, $remotePath, 'postgres');

            return $bytes;
        }

        if ($db->engine === 'mongodb') {
            throw new \RuntimeException(__('MongoDB backup export to server path is not supported yet — use on-server mongodump from Advanced.'));
        }

        if ($db->engine === 'clickhouse') {
            throw new \RuntimeException(__('ClickHouse backups are not supported in this workspace yet.'));
        }

        $bytes = $this->dumpToPathWithAdminFallback($db, $server, $remotePath, $emit, 'mysql', $this->hasMysqlRootCredential($server),
            fn (): int => $this->remoteExec->mysqldumpToPath($server, $db->name, $db->username, $db->password, $remotePath),
            fn (): int => $this->remoteExec->mysqldumpAdminToPath($server, $db->name, $remotePath),
        );
        $this->assertRemoteDumpLooksValid($server, $remotePath, 'mysql');

        return $bytes;
    }

    private function dumpToString(ServerDatabase $db, Server $server, string $extension, ConsoleEmitter $emit): string
    {
        if ($db->engine === 'sqlite') {
            $maxBytes = (int) config('server_database.sqlite_backup_max_bytes', 256 * 1024 * 1024);

            return $this->remoteExec->sqliteBackup($server, $this->provisioner->resolvedSqlitePath($db), $maxBytes);
        }

        $contents = match ($db->engine) {
            'postgres' => $this->dumpToStringWithAdminFallback($db, $emit, 'postgres', true,
                fn (): string => $this->remoteExec->pgDump($server, $db->name, $db->username, $db->password),
                fn (): string => $this->remoteExec->pgDumpAdmin($server, $db->name),
            ),
            'mongodb' => $this->remoteExec->mongodump($server, $db->name, $db->username, $db->password),
            'clickhouse' => throw new \RuntimeException(__('ClickHouse backups are not supported in this workspace yet.')),
            default => $this->dumpToStringWithAdminFallback($db, $emit, 'mysql', $this->hasMysqlRootCredential($server),
                fn (): string => $this->remoteExec->mysqldump($server, $db->name, $db->username, $db->password),
                fn (): string => $this->remoteExec->mysqldumpAdmin($server, $db->name),
            ),
        };

        if (ServerDatabaseDumpOutputValidator::looksLikeFailedDump($db->engine, $contents)) {
            throw new \RuntimeException('Dump command failed: '.substr($contents, 0, 1200));
        }

        if ($contents === '') {
            throw new \RuntimeException('Backup produced an empty file.');
        }

        return $contents;
    }

    /**
     * Run a dump-to-path with the per-database app user, and — if those stored
     * credentials have drifted from the box and the user is denied — retry with
     * dply's server admin/root credentials. Keeps scheduled backups working
     * through a credential rotation that never made it back to the
     * server_databases row, rather than hard-failing with "Access denied …
     * (using password: YES)". Any NON-auth failure is rethrown untouched so we
     * don't mask real problems (missing DB, disk full) behind a pointless retry.
     *
     * @param  callable():int  $primary   dump with the app user → byte count
     * @param  callable():int  $fallback  dump with admin/root creds → byte count
     */
    private function dumpToPathWithAdminFallback(ServerDatabase $db, Server $server, string $remotePath, ConsoleEmitter $emit, string $engine, bool $adminAvailable, callable $primary, callable $fallback): int
    {
        try {
            return $primary();
        } catch (\RuntimeException $e) {
            // mysqldump/pg_dump redirect their error INTO the dump file
            // (`> file 2>&1`), so the thrown message is usually empty — read the
            // file head to learn why it actually failed.
            $reason = $this->remoteDumpHead($server, $remotePath);
            $message = $reason !== '' ? $reason : $e->getMessage();

            if (! $adminAvailable || ! $this->looksLikeAuthFailure($engine, $message)) {
                throw $reason !== '' ? new \RuntimeException('Dump command failed: '.Str::limit($reason, 800), 0, $e) : $e;
            }

            $emit->step('db', __('App user :user was denied — retrying the dump with the server admin credentials.', [
                'user' => $db->username ?: '(unknown)',
            ]));

            return $fallback();
        }
    }

    /**
     * String-output counterpart to {@see dumpToPathWithAdminFallback}: the
     * stdout dump doesn't throw on auth failure, it returns the error text, so
     * we detect it from the output and retry with admin credentials.
     *
     * @param  callable():string  $primary
     * @param  callable():string  $fallback
     */
    private function dumpToStringWithAdminFallback(ServerDatabase $db, ConsoleEmitter $emit, string $engine, bool $adminAvailable, callable $primary, callable $fallback): string
    {
        $out = $primary();

        if (! ServerDatabaseDumpOutputValidator::looksLikeFailedDump($engine, $out)) {
            return $out;
        }
        if (! $adminAvailable || ! $this->looksLikeAuthFailure($engine, $out)) {
            // Let the caller's existing validation surface a non-auth failure.
            return $out;
        }

        $emit->step('db', __('App user :user was denied — retrying the dump with the server admin credentials.', [
            'user' => $db->username ?: '(unknown)',
        ]));

        return $fallback();
    }

    /** Whether a MySQL root credential is on file to fall back to. */
    private function hasMysqlRootCredential(Server $server): bool
    {
        $cred = $this->remoteExec->adminCredential($server);

        return $cred !== null && (string) $cred->mysql_root_password !== '';
    }

    /** Read the head of a remote dump file to recover the DB error written into it. */
    private function remoteDumpHead(Server $server, string $remotePath): string
    {
        try {
            [$head] = $this->remoteExec->shellRunWithExit($server, 'head -c 1200 '.escapeshellarg($remotePath).' 2>/dev/null || true', 60);

            return trim((string) $head);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Does this dump error look like a credential/authentication rejection (as
     * opposed to a missing database, permission-on-table, disk, etc.)? Only
     * those warrant a root-credential retry.
     */
    private function looksLikeAuthFailure(string $engine, string $message): bool
    {
        $m = mb_strtolower($message);

        if (str_contains($engine, 'postgres')) {
            return str_contains($m, 'password authentication failed')
                || str_contains($m, 'authentication failed')
                || str_contains($m, 'no password supplied')
                || str_contains($m, 'peer authentication')
                || (str_contains($m, 'role ') && str_contains($m, 'does not exist'));
        }

        // mysql / mariadb
        return str_contains($m, 'access denied')
            || str_contains($m, 'error: 1045')
            || str_contains($m, '(using password');
    }

    private function assertRemoteDumpLooksValid(Server $server, string $remotePath, string $engine): void
    {
        $headCmd = 'head -c 1200 '.escapeshellarg($remotePath).' 2>/dev/null || true';
        [$head] = $this->remoteExec->shellRunWithExit($server, $headCmd, 60);

        if (ServerDatabaseDumpOutputValidator::looksLikeFailedDump($engine, $head)) {
            $this->remoteExec->shellRunWithExit($server, 'rm -f '.escapeshellarg($remotePath), 30);

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

    /**
     * Ensure a destination object can actually be fetched. AWS cold tiers
     * (Glacier Flexible / Deep Archive) return InvalidObjectState on GET until
     * thawed, so HeadObject the key and, when it's archived and not yet
     * restored, request a RestoreObject and throw a status message. Instant
     * classes (Standard/IA/Intelligent-Tiering/Glacier IR) and non-AWS
     * providers (incl. DO Spaces cold, which reads instantly) pass straight
     * through.
     */
    private function ensureDestinationObjectRetrievable(ServerDatabaseBackup $backup): void
    {
        $backup->loadMissing('backupConfiguration');
        $configuration = $backup->backupConfiguration;
        if ($configuration === null || $backup->s3_key === null) {
            return; // presignedGetUrl() surfaces the clearer error.
        }

        // Only AWS S3 has classes that require a thaw before download.
        if ($configuration->provider !== BackupConfiguration::PROVIDER_AWS_S3) {
            return;
        }

        $restoreClasses = array_keys(array_filter(
            (array) config('object_storage.providers.aws_s3.storage_classes', []),
            static fn ($meta): bool => (bool) ($meta['restore'] ?? false),
        ));
        if ($restoreClasses === []) {
            return;
        }

        $s3 = $this->s3Factory->forConfiguration($configuration);

        try {
            $head = $s3['client']->headObject([
                'Bucket' => $backup->s3_bucket,
                'Key' => $backup->s3_key,
            ]);
        } catch (\Throwable) {
            return; // Let the download attempt surface real errors (missing object, etc.).
        }

        $class = (string) ($head['StorageClass'] ?? '');
        if (! in_array($class, $restoreClasses, true)) {
            return; // Instantly retrievable.
        }

        $restore = (string) ($head['Restore'] ?? '');
        if (str_contains($restore, 'ongoing-request="false"')) {
            return; // Already thawed and temporarily available.
        }
        if (str_contains($restore, 'ongoing-request="true"')) {
            throw new \RuntimeException(__('This backup is in cold storage and is still being restored. Check back shortly, then download again.'));
        }

        $days = max(1, (int) config('object_storage.restore_available_days', 7));
        $tier = (string) config('object_storage.restore_tier', 'Standard');

        try {
            $s3['client']->restoreObject([
                'Bucket' => $backup->s3_bucket,
                'Key' => $backup->s3_key,
                'RestoreRequest' => [
                    'Days' => $days,
                    'GlacierJobParameters' => ['Tier' => $tier],
                ],
            ]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'RestoreAlreadyInProgress') {
                throw new \RuntimeException(__('This backup is in cold storage and is already being restored. Check back shortly, then download again.'));
            }

            throw new \RuntimeException(__('Could not start the cold-storage restore: :err', ['err' => $e->getAwsErrorMessage() ?: $e->getMessage()]));
        }

        throw new \RuntimeException(__('This backup is in cold storage (:class). A restore has been requested — it usually takes a few hours (Deep Archive up to ~12h). It stays downloadable for :days days once ready; try the download again then.', [
            'class' => $class,
            'days' => $days,
        ]));
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
            'rm -f '.escapeshellarg($path),
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
