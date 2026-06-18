<?php

declare(strict_types=1);

namespace App\Modules\Snapshots\Services;

use App\Models\Snapshot;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Stores snapshots on the server's own filesystem, with a hard 7-day
 * TTL (Q19) so they don't accumulate. This destination is reserved
 * for the transient safety-net case — automatic snapshots taken
 * before destructive operations (migrate:rollback, wp db drop, etc.).
 *
 * Operators looking for durable backups configure an S3 destination
 * via {@see S3Destination} instead.
 */
class LocalDiskDestination implements SnapshotDestination
{
    public const TTL_DAYS = 7;

    public const STORAGE_DIR = '/home/dply/snapshots';

    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    public function kind(): string
    {
        return Snapshot::DESTINATION_LOCAL_DISK;
    }

    public function persist(Snapshot $snapshot, string $dumpRemotePath, int $bytes): Snapshot
    {
        $site = $snapshot->site;

        // Move the dump from /tmp into the per-site snapshot dir so a
        // crash in the orchestrator doesn't leave it where /tmp sweeps
        // would chew it.
        $finalPath = sprintf('%s/%s/%s.sql.gz', self::STORAGE_DIR, $site->slug, basename($dumpRemotePath, '.sql.gz'));
        $this->executor->runInlineBash(
            server: $site->server,
            name: 'snapshot:local-stash',
            inlineBash: sprintf(
                'sudo -u dply mkdir -p %s && sudo -u dply mv %s %s',
                escapeshellarg(dirname($finalPath)),
                escapeshellarg($dumpRemotePath),
                escapeshellarg($finalPath),
            ),
            timeoutSeconds: 30,
        );

        $snapshot->update([
            'destination' => Snapshot::DESTINATION_LOCAL_DISK,
            'local_path' => $finalPath,
            'bytes' => $bytes,
            'expires_at' => now()->addDays(self::TTL_DAYS),
            'status' => Snapshot::STATUS_COMPLETED,
        ]);

        return $snapshot;
    }

    public function restore(Snapshot $snapshot): void
    {
        if ($snapshot->local_path === null) {
            throw new \RuntimeException('Snapshot has no local_path — cannot restore from local disk.');
        }

        // Stream back through gunzip into mysql/psql per engine.
        $cmd = match ($snapshot->engine) {
            'postgres', 'postgres17', 'postgres18' => sprintf(
                'gunzip -c %s | psql',
                escapeshellarg($snapshot->local_path),
            ),
            default => sprintf(
                'gunzip -c %s | mysql',
                escapeshellarg($snapshot->local_path),
            ),
        };

        $this->executor->runInlineBash(
            server: $snapshot->site->server,
            name: 'snapshot:local-restore',
            inlineBash: $cmd,
            timeoutSeconds: 600,
        );
    }
}
