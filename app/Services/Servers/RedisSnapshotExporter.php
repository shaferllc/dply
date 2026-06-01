<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\BackupConfiguration;
use App\Models\RedisSnapshot;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Support\Servers\CacheServiceStats;
use Illuminate\Support\Str;

/**
 * Run a one-shot RDB snapshot of a redis-family cache service and upload the
 * resulting dump.rdb to the configured destination (S3-style for v1).
 *
 * High-level flow:
 *
 *   1. BGSAVE on the engine via redis-cli.
 *   2. Poll LASTSAVE until the timestamp advances OR a bounded timeout fires.
 *   3. CONFIG GET dir + dbfilename to locate the on-disk RDB file (per-engine).
 *   4. `cp` to a temp path so the source dump isn't truncated mid-upload.
 *   5. `curl` upload via presigned PUT URL (same shape as DatabaseBackupExporter).
 *   6. Update the {@see RedisSnapshot} row with bytes + s3_key.
 *
 * Failure at any step throws \RuntimeException; the caller (job) catches and
 * marks the row failed with error_message.
 */
final class RedisSnapshotExporter
{
    public const PRESIGNED_PUT_TTL_MINUTES = 30;

    public const PRESIGNED_GET_TTL_MINUTES = 30;

    public const BGSAVE_TIMEOUT_SECONDS = 1800;

    public function __construct(
        private readonly ServerDatabaseRemoteExec $remoteExec,
        private readonly ExecuteRemoteTaskOnServer $executor,
        private readonly DatabaseBackupS3ClientFactory $s3Factory,
    ) {}

    public function export(RedisSnapshot $snapshot): void
    {
        $snapshot->loadMissing(['cacheService', 'server.organization', 'backupConfiguration']);

        $row = $snapshot->cacheService;
        $server = $snapshot->server;
        if ($row === null || $server === null) {
            throw new \RuntimeException('Snapshot is missing its cache service or server.');
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            throw new \RuntimeException('Engine ['.$row->engine.'] does not support RDB snapshots.');
        }

        $configuration = $snapshot->backupConfiguration;
        if (! $configuration instanceof BackupConfiguration) {
            throw new \RuntimeException('Snapshot has no destination configured. Only S3-style destinations are supported in v1.');
        }

        $this->exportToDestination($snapshot, $row, $server, $configuration);
    }

    private function exportToDestination(
        RedisSnapshot $snapshot,
        ServerCacheService $row,
        Server $server,
        BackupConfiguration $configuration,
    ): void {
        $s3 = $this->s3Factory->forConfiguration($configuration);

        $lastSaveBefore = $this->lastSave($server, $row);
        $this->bgsave($server, $row);
        $this->waitForLastSaveAdvance($server, $row, $lastSaveBefore);

        $rdbPath = $this->resolveRdbPath($server, $row);
        $tempPath = '/tmp/dply-redis-snapshot-'.$snapshot->id.'.rdb';

        // Copy first so the upload reads a stable file even if the engine
        // starts the next BGSAVE while we're uploading.
        $copyCmd = sprintf(
            'set -e; cp -p %s %s; chmod 600 %s',
            escapeshellarg($rdbPath),
            escapeshellarg($tempPath),
            escapeshellarg($tempPath),
        );

        [$copyOut, $copyExit] = $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg($copyCmd), 120);
        if ($copyExit !== 0) {
            throw new \RuntimeException('Failed to stage RDB file: '.Str::limit(trim($copyOut), 600));
        }

        // Read bytes from the temp copy so the recorded size matches the upload.
        [$statOut, $statExit] = $this->remoteExec->shellRunWithExit(
            $server,
            'bash -lc '.escapeshellarg('stat -c %s '.escapeshellarg($tempPath).' 2>/dev/null || stat -f %z '.escapeshellarg($tempPath)),
            30,
        );
        if ($statExit !== 0 || ! is_numeric(trim($statOut))) {
            $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg('rm -f '.escapeshellarg($tempPath)), 30);

            throw new \RuntimeException('Failed to size RDB file.');
        }
        $bytes = (int) trim($statOut);

        if ($bytes <= 0) {
            $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg('rm -f '.escapeshellarg($tempPath)), 30);

            throw new \RuntimeException('RDB file is empty — engine may not have any data.');
        }

        $key = $this->buildObjectKey($s3['key_prefix'], $server, $row, $snapshot);
        $contentType = 'application/octet-stream';

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

        [$uploadOut, $uploadExit] = $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg($uploadCmd), 3600);
        if ($uploadExit !== 0) {
            $this->remoteExec->shellRunWithExit($server, 'bash -lc '.escapeshellarg('rm -f '.escapeshellarg($tempPath)), 30);

            throw new \RuntimeException('S3 upload failed: '.Str::limit(trim($uploadOut), 800));
        }

        $snapshot->update([
            'status' => RedisSnapshot::STATUS_COMPLETED,
            'storage_kind' => RedisSnapshot::STORAGE_DESTINATION,
            's3_bucket' => $s3['bucket'],
            's3_key' => $key,
            'bytes' => $bytes,
        ]);
    }

    private function bgsave(Server $server, ServerCacheService $row): void
    {
        $cli = CacheServiceStats::binaryFor($row->engine);
        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' '
            : '';

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:snapshot-bgsave:'.$row->engine,
            $authFlag.escapeshellarg($cli).' -p '.(int) $row->port.' BGSAVE 2>/dev/null',
            timeoutSeconds: 60,
            asRoot: false,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException('BGSAVE refused by engine: '.Str::limit(trim($output->buffer), 400));
        }
    }

    private function lastSave(Server $server, ServerCacheService $row): int
    {
        $cli = CacheServiceStats::binaryFor($row->engine);
        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' '
            : '';

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:snapshot-lastsave:'.$row->engine,
            $authFlag.escapeshellarg($cli).' -p '.(int) $row->port.' LASTSAVE 2>/dev/null',
            timeoutSeconds: 30,
            asRoot: false,
        );

        return $output->exitCode === 0 ? (int) trim($output->buffer) : 0;
    }

    /**
     * Poll LASTSAVE every 2s until it advances past $previous or BGSAVE_TIMEOUT_SECONDS
     * elapses. Large datasets can take a while; the 30-minute ceiling protects against a
     * stuck save without prematurely declaring failure on a healthy long-running BGSAVE.
     */
    private function waitForLastSaveAdvance(Server $server, ServerCacheService $row, int $previous): void
    {
        $deadline = time() + self::BGSAVE_TIMEOUT_SECONDS;
        do {
            sleep(2);
            $now = $this->lastSave($server, $row);
            if ($now > $previous) {
                return;
            }
        } while (time() < $deadline);

        throw new \RuntimeException('BGSAVE timed out after '.self::BGSAVE_TIMEOUT_SECONDS.'s without LASTSAVE advancing.');
    }

    /**
     * Resolve the engine's actual dump.rdb path from CONFIG GET dir + dbfilename.
     * More reliable than hardcoding per-engine paths because operators can override
     * these via the Configure subtab or by hand.
     */
    private function resolveRdbPath(Server $server, ServerCacheService $row): string
    {
        $cli = CacheServiceStats::binaryFor($row->engine);
        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' '
            : '';
        $port = (int) $row->port;

        $script = $authFlag.escapeshellarg($cli).' -p '.$port.' CONFIG GET dir; '
            .'echo "---FILE---"; '
            .$authFlag.escapeshellarg($cli).' -p '.$port.' CONFIG GET dbfilename';

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:snapshot-rdbpath:'.$row->engine,
            $script,
            timeoutSeconds: 30,
            asRoot: false,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException('Could not resolve RDB path: '.Str::limit(trim($output->buffer), 400));
        }

        [$dirBlock, $fileBlock] = array_pad(explode('---FILE---', $output->buffer, 2), 2, '');
        $dir = $this->configValueTail($dirBlock);
        $filename = $this->configValueTail($fileBlock);

        if ($dir === '' || $filename === '') {
            throw new \RuntimeException('Engine did not report a valid dir/dbfilename.');
        }

        return rtrim($dir, '/').'/'.$filename;
    }

    private function configValueTail(string $block): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));

        return (string) ($lines[count($lines) - 1] ?? '');
    }

    private function buildObjectKey(string $prefix, Server $server, ServerCacheService $row, RedisSnapshot $snapshot): string
    {
        $prefix = trim($prefix, '/');
        $tail = sprintf('redis-snapshots/%s/%s/%s.rdb', $server->id, $row->engine, $snapshot->id);

        return $prefix !== '' ? $prefix.'/'.$tail : $tail;
    }
}
