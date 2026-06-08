<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\Snapshot;
use App\Services\Snapshots\SnapshotDestinationFactory;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue-side runner for a manual site database snapshot taken from the Snapshots
 * workspace Databases tab. The dump runs over SSH and can take many minutes, so
 * it must never run in the web request (PHP max_execution_time) — the component
 * dispatches us and the operator polls the history list for the resulting row.
 *
 * Wraps {@see SnapshotService::take()} with the org's preferred destination
 * (S3 when configured, else local disk), mirroring the WordPress "Take snapshot"
 * action but off the render path.
 */
class TakeSiteSnapshotJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1900;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
    ) {}

    public function handle(SnapshotService $snapshots, SnapshotDestinationFactory $destinations): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if ($site === null) {
            return;
        }

        // SnapshotService records its own success/failure audit events; we just
        // drive it. A thrown dump error will surface as a failed-job entry.
        $snapshots->take(
            site: $site,
            destination: $destinations->preferred(),
            reason: Snapshot::REASON_MANUAL,
            userId: $this->userId,
        );
    }
}
