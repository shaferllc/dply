<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Actions\Cloud\CreateCloudWorker;
use App\Models\CloudWorker;
use App\Models\Site;
use Illuminate\Console\Command;
use Throwable;

/**
 * Add a background worker (queue worker or scheduler) to a Cloud site.
 *
 *   dply:cloud:worker:add --site=<id|slug|name> \
 *       [--command="php artisan queue:work"] [--size=small] \
 *       [--count=1] [--type=worker|scheduler] [--name=<name>]
 *
 * Creates a CloudWorker row and queues SyncCloudWorkersJob, which
 * pushes the worker into the backend's app spec and rolls a deploy.
 *
 * Only DigitalOcean App Platform sites support workers — App Runner
 * sites are rejected with a clear message.
 */
class CloudWorkerAddCommand extends Command
{
    protected $signature = 'dply:cloud:worker:add
        {--site= : Site ID, slug, or name}
        {--command= : Run command (default "php artisan queue:work"; ignored for the scheduler)}
        {--size=small : Compute tier — small, medium, large, or xlarge}
        {--count=1 : Instance count (forced to 1 for the scheduler)}
        {--type=worker : Worker type — worker or scheduler}
        {--name= : Optional component name}';

    protected $description = 'Add a background worker or scheduler to a Cloud site.';

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

        try {
            $worker = (new CreateCloudWorker)->handle($site, [
                'type' => (string) $this->option('type'),
                'command' => (string) $this->option('command'),
                'size' => (string) $this->option('size'),
                'instance_count' => (int) $this->option('count'),
                'name' => (string) $this->option('name'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s "%s" added to site "%s" — running `%s` ×%d (%s) [%s]. Sync queued.',
            $worker->isScheduler() ? 'Scheduler' : 'Worker',
            $worker->name,
            $site->name,
            $worker->effectiveCommand(),
            $worker->effectiveInstanceCount(),
            $worker->size,
            $worker->id,
        ));

        return self::SUCCESS;
    }
}
