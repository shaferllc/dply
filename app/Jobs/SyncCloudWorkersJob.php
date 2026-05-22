<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CloudWorker;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Pushes a Cloud site's background workers (queue workers + the
 * scheduler) into its backend deployment spec and rolls a new
 * deployment so the change takes effect.
 *
 * Dispatched on every CloudWorker create / scale / delete — the
 * backend rebuilds the `workers` array from the current CloudWorker
 * rows each call, so a deleted worker is simply omitted from the
 * pushed spec.
 *
 * The site's CloudWorker rows are flipped to ACTIVE once the spec is
 * accepted by the backend; on any failure they are marked FAILED with
 * the error recorded in `meta`.
 */
class SyncCloudWorkersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            $this->markWorkers($site, CloudWorker::STATUS_FAILED, 'No backend or credential resolvable for the site.');

            return;
        }

        if (! $backend->supportsWorkers()) {
            $this->markWorkers($site, CloudWorker::STATUS_FAILED, 'The site backend does not support background workers.');

            return;
        }

        try {
            $backend->syncWorkers($site, $credential);
        } catch (Throwable $e) {
            $this->markWorkers($site, CloudWorker::STATUS_FAILED, $e->getMessage());

            throw $e;
        }

        $this->markWorkers($site, CloudWorker::STATUS_ACTIVE, null);
    }

    /**
     * Flip every non-deleting CloudWorker row on the site to a status,
     * recording (or clearing) an error on its meta.
     */
    private function markWorkers(Site $site, string $status, ?string $error): void
    {
        $workers = CloudWorker::query()
            ->where('site_id', $site->id)
            ->where('status', '!=', CloudWorker::STATUS_DELETING)
            ->get();

        foreach ($workers as $worker) {
            $meta = is_array($worker->meta) ? $worker->meta : [];
            if ($error !== null) {
                $meta['error'] = $error;
                $meta['error_at'] = now()->toIso8601String();
            } else {
                unset($meta['error'], $meta['error_at']);
                $meta['synced_at'] = now()->toIso8601String();
            }
            $worker->forceFill(['status' => $status, 'meta' => $meta])->save();
        }
    }
}
