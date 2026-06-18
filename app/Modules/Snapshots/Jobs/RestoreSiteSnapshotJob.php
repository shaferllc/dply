<?php

declare(strict_types=1);

namespace App\Modules\Snapshots\Jobs;

use App\Console\Commands\SnapshotRestoreCommand;
use App\Models\Snapshot;
use App\Modules\Snapshots\Services\SnapshotDestinationFactory;
use App\Modules\Snapshots\Services\SnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue-side runner for a destructive site database snapshot restore from the
 * Snapshots workspace Databases tab. Streams the dump back into the live DB over
 * SSH (long-running) so it cannot run in the web request.
 *
 * Picks the destination matching the snapshot's storage so the restore pipeline
 * knows where to fetch bytes from — mirrors {@see SnapshotRestoreCommand}.
 */
class RestoreSiteSnapshotJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1900;

    public function __construct(
        public int $snapshotId,
        public ?string $userId = null,
    ) {}

    public function handle(SnapshotService $service, SnapshotDestinationFactory $destinations): void
    {
        $snapshot = Snapshot::query()->with('site.server')->find($this->snapshotId);
        if ($snapshot === null || $snapshot->site === null) {
            return;
        }

        $destination = match ($snapshot->destination) {
            Snapshot::DESTINATION_S3 => $destinations->s3(),
            default => $destinations->localDisk(),
        };

        if ($destination === null) {
            throw new \RuntimeException('Snapshot is in S3 but no S3 bucket is configured.');
        }

        $service->restore($snapshot, $destination, userId: $this->userId);
    }
}
