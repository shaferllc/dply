<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Jobs\SyncCloudWorkersJob;
use App\Models\CloudWorker;
use Illuminate\Console\Command;

/**
 * Remove a background worker from a Cloud site.
 *
 *   dply:cloud:worker:remove <worker>
 *
 * Deletes the CloudWorker row and queues SyncCloudWorkersJob, which
 * re-pushes the app spec without the removed component and rolls a
 * deploy — the backend rebuilds the `workers` array from the
 * remaining rows, so the removed component is simply omitted.
 */
class CloudWorkerRemoveCommand extends Command
{
    protected $signature = 'dply:cloud:worker:remove
        {worker : CloudWorker ID}';

    protected $description = 'Remove a background worker from a Cloud site.';

    public function handle(): int
    {
        $needle = trim((string) $this->argument('worker'));
        $worker = CloudWorker::query()->find($needle);
        if ($worker === null) {
            $this->error("Worker not found: {$needle}");

            return self::FAILURE;
        }

        $siteId = $worker->site_id;
        $name = $worker->name;
        $worker->delete();

        SyncCloudWorkersJob::dispatch($siteId);

        $this->info(sprintf('Worker "%s" removed. Sync queued — the backend will drop the component on the next roll.', $name));

        return self::SUCCESS;
    }
}
