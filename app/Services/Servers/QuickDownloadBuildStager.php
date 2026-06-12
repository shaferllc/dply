<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\QuickDownload;
use App\Services\Backups\BackupStagingS3ClientFactory;
use Illuminate\Support\Str;

/**
 * Drives a queued {@see QuickDownload} from `building` to `ready` (or `failed`):
 * builds the fresh artifact on the server, uploads it into the operator-managed
 * download bucket, records the staged object, and notifies the requester on both
 * channels. The proxy route streams it once and deletes it; the sweeper mops up
 * anything left after the 4h window.
 *
 * Never throws — every failure (incl. the over-cap case) lands on the row and a
 * failure notification, so the polling UI and the walked-away user both learn the
 * outcome.
 */
final class QuickDownloadBuildStager
{
    public function __construct(
        private readonly QuickDownloadStreamer $streamer,
        private readonly BackupStagingS3ClientFactory $staging,
        private readonly QuickDownloadNotifier $notifier,
    ) {}

    public function build(QuickDownload $row): void
    {
        // Idempotency: only act on a fresh request. A retry that already reached a
        // terminal/ready state must not rebuild or re-notify.
        if (! in_array($row->status, [QuickDownload::STATUS_PENDING, QuickDownload::STATUS_BUILDING], true)) {
            return;
        }

        if (! $this->staging->enabled()) {
            $this->fail($row, __('Downloads aren’t configured yet — the staging bucket is missing.'));

            return;
        }

        $row->update(['status' => QuickDownload::STATUS_BUILDING]);

        try {
            // Builds into /tmp on the box and enforces the size cap before anything
            // moves. Throws QuickDownloadTooLargeException when over the cap.
            $prepared = $this->streamer->prepareFor($row);
        } catch (QuickDownloadTooLargeException $e) {
            $this->fail($row, $e->getMessage(), overCap: true);

            return;
        } catch (\Throwable $e) {
            $this->fail($row, Str::limit($e->getMessage(), 600));

            return;
        }

        try {
            $s3 = $this->staging->make();
            $key = $this->objectKey($s3['key_prefix'], $row, $prepared->filename);

            $putUrl = (string) $s3['client']->createPresignedRequest(
                $s3['client']->getCommand('PutObject', [
                    'Bucket' => $s3['bucket'],
                    'Key' => $key,
                    'ContentType' => $prepared->mime,
                ]),
                '+'.(int) config('backup_staging.presign_put_minutes', 30).' minutes',
            )->getUri();

            $this->streamer->uploadToPresignedPut($prepared, $putUrl);

            $row->update([
                'status' => QuickDownload::STATUS_READY,
                'bucket' => $s3['bucket'],
                'object_key' => $key,
                'bytes' => $prepared->bytes,
                'filename' => $prepared->filename,
                'mime' => $prepared->mime,
                'error_message' => null,
                'expires_at' => now()->addMinutes((int) config('backup_staging.ttl_minutes', 240)),
            ]);
        } catch (\Throwable $e) {
            $this->fail($row, Str::limit($e->getMessage(), 600));

            return;
        }

        $this->notifier->ready($row->fresh() ?? $row);
    }

    /**
     * Open a read stream onto the staged object so the proxy route can pipe it to
     * the browser without the control plane buffering the whole file.
     */
    public function openReadStream(QuickDownload $row): \Psr\Http\Message\StreamInterface
    {
        if (! $row->hasStagedObject()) {
            throw new \RuntimeException(__('This download is no longer available.'));
        }

        $s3 = $this->staging->make();
        $result = $s3['client']->getObject([
            'Bucket' => $row->bucket,
            'Key' => $row->object_key,
            '@http' => ['stream' => true],
        ]);

        return $result['Body'];
    }

    /** Mark a fully-downloaded artifact consumed and delete its staged object. */
    public function consume(QuickDownload $row): void
    {
        $this->deleteObject($row);

        $row->update([
            'status' => QuickDownload::STATUS_CONSUMED,
            'consumed_at' => now(),
        ]);
    }

    /** Best-effort delete of the staged bucket object (used on consume + sweep). */
    public function deleteObject(QuickDownload $row): void
    {
        if (! $row->hasStagedObject()) {
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

    private function fail(QuickDownload $row, string $message, bool $overCap = false): void
    {
        $row->update([
            'status' => QuickDownload::STATUS_FAILED,
            'error_message' => $message,
        ]);

        $this->notifier->failed($row->fresh() ?? $row, $overCap);
    }

    /** Deterministic, namespaced key so a re-stage overwrites rather than orphans. */
    private function objectKey(string $prefix, QuickDownload $row, string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $tail = (string) $row->id.($ext !== '' ? '.'.$ext : '');

        $segments = array_filter([
            trim($prefix, '/'),
            'quick-downloads',
            $tail,
        ], static fn (string $s): bool => $s !== '');

        return implode('/', $segments);
    }
}
