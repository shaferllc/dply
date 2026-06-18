<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Models\Snapshot;
use App\Modules\Snapshots\Services\LocalDiskDestination;
use App\Modules\Snapshots\Services\SnapshotDestinationFactory;
use App\Modules\Snapshots\Services\SnapshotService;
use Illuminate\Console\Command;

/**
 * dply:snapshot:restore <snapshot-id> [--no-confirm] [--user=email]
 *
 * Restore a snapshot back into the live database. Always destructive
 * (overwrites schema + data); requires explicit `--no-confirm` for CI
 * use, otherwise prompts the operator.
 */
class SnapshotRestoreCommand extends Command
{
    use ResolvesSiteForCliCommand;

    protected $signature = 'dply:snapshot:restore
        {snapshot : Numeric snapshot id (from dply:snapshot:list)}
        {--user= : User email to attribute the restore to (audit trail)}
        {--no-confirm : Skip the destructive-action prompt}';

    protected $description = 'Restore a snapshot into the live database (destructive).';

    public function handle(SnapshotService $service, SnapshotDestinationFactory $destinations): int
    {
        $snapshot = Snapshot::query()->find((int) $this->argument('snapshot'));
        if ($snapshot === null) {
            $this->error('Snapshot not found.');

            return self::FAILURE;
        }

        $site = $snapshot->site;
        if ($site === null) {
            $this->error('Snapshot orphaned — site no longer exists.');

            return self::FAILURE;
        }

        if (! $this->option('no-confirm')) {
            $confirmed = $this->confirm("Restore snap-{$snapshot->id} into {$site->name}'s live DB? This OVERWRITES current data.");
            if (! $confirmed) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        // Pick the destination matching the snapshot's storage so the
        // restore code path knows where to fetch bytes from. S3-stored
        // snapshots restore via S3Destination's presigned-GET pipeline;
        // local-disk via LocalDiskDestination's gunzip pipe.
        $destination = match ($snapshot->destination) {
            Snapshot::DESTINATION_S3 => $destinations->s3(),
            default => $destinations->localDisk(),
        };

        if ($destination === null) {
            $this->error('Snapshot is in S3 but no S3 bucket is configured. Set DPLY_SNAPSHOT_S3_BUCKET.');

            return self::FAILURE;
        }

        $caller = $this->resolveActingUser($site, $this->option('user'));

        try {
            $service->restore($snapshot, $destination, userId: $caller?->getKey());
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored snap-{$snapshot->id} into {$site->name}.");

        return self::SUCCESS;
    }
}
