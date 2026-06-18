<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Jobs\SyncCloudWorkersJob;
use App\Models\CloudWorker;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Disable the Laravel scheduler on a Cloud site.
 *
 *   dply:cloud:scheduler:disable --site=<id|slug|name>
 *
 * Convenience wrapper — removes the site's scheduler-type CloudWorker
 * and queues SyncCloudWorkersJob so the backend drops the scheduler
 * component on the next roll.
 */
class CloudSchedulerDisableCommand extends Command
{
    protected $signature = 'dply:cloud:scheduler:disable
        {--site= : Site ID, slug, or name}';

    protected $description = 'Disable the Laravel scheduler on a Cloud site.';

    public function handle(): int
    {
        $needle = trim((string) $this->option('site'));
        if ($needle === '') {
            $this->error('--site=<id|slug|name> is required.');

            return self::FAILURE;
        }

        $site = Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $scheduler = CloudWorker::query()
            ->where('site_id', $site->id)
            ->where('type', CloudWorker::TYPE_SCHEDULER)
            ->first();
        if ($scheduler === null) {
            $this->warn("Site \"{$site->name}\" has no scheduler enabled.");

            return self::SUCCESS;
        }

        $scheduler->delete();
        SyncCloudWorkersJob::dispatch($site->id);

        $this->info(sprintf('Scheduler disabled on site "%s". Sync queued.', $site->name));

        return self::SUCCESS;
    }
}
