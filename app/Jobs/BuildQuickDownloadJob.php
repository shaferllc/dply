<?php

namespace App\Jobs;

use App\Jobs\Middleware\SerializeServerSsh;
use App\Models\QuickDownload;
use App\Services\Servers\QuickDownloadBuildStager;
use App\Services\Servers\QuickDownloadNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds a queued quick-download artifact on the server and uploads it into the
 * download-staging bucket. The build runs over SSH, so it must never run inline
 * in an HTTP request and is serialized per target server (one box, one build at a
 * time) to avoid saturating sshd. The UI polls the row for ready/failed and the
 * requester is notified in-app + by email on completion.
 */
class BuildQuickDownloadJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /**
     * A contended SSH-slot release is not an exception, but a real handler error
     * must fail fast rather than re-SSH. {@see SerializeServerSsh} contract.
     */
    public int $maxExceptions = 1;

    public function __construct(
        public string $quickDownloadId,
        public string $serverId,
    ) {
        $q = config('backup_staging.upload_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SerializeServerSsh($this->serverId)];
    }

    /** Bound how long the job waits for the per-server SSH slot before giving up. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(QuickDownloadBuildStager $stager): void
    {
        $row = QuickDownload::query()
            ->with(['server', 'site.server', 'serverDatabase.server', 'requestedBy'])
            ->find($this->quickDownloadId);

        if ($row === null) {
            return;
        }

        $stager->build($row);
    }

    /** Last-resort: a job that dies outside the stager's own try/catch. */
    public function failed(\Throwable $e): void
    {
        $row = QuickDownload::query()->with('requestedBy', 'server', 'serverDatabase')->find($this->quickDownloadId);
        if ($row === null || in_array($row->status, [QuickDownload::STATUS_READY, QuickDownload::STATUS_CONSUMED], true)) {
            return;
        }

        $row->update([
            'status' => QuickDownload::STATUS_FAILED,
            'error_message' => \Illuminate\Support\Str::limit($e->getMessage(), 600),
        ]);

        app(QuickDownloadNotifier::class)->failed($row->fresh() ?? $row);
    }
}
