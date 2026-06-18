<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Actions\Cloud\CreateCloudWorker;
use App\Models\CloudWorker;
use App\Models\Site;
use Illuminate\Console\Command;
use Throwable;

/**
 * Enable the Laravel scheduler on a Cloud site.
 *
 *   dply:cloud:scheduler:enable --site=<id|slug|name> [--size=small]
 *
 * Convenience wrapper around CreateCloudWorker — creates a
 * scheduler-type CloudWorker (a worker pinned to one instance running
 * `php artisan schedule:work`). App Platform has no native cron, so a
 * long-running scheduler loop is how a Cloud site gets one.
 *
 * Only DigitalOcean App Platform sites support workers — App Runner
 * sites are rejected with a clear message.
 */
class CloudSchedulerEnableCommand extends Command
{
    protected $signature = 'dply:cloud:scheduler:enable
        {--site= : Site ID, slug, or name}
        {--size=small : Compute tier — small, medium, large, or xlarge}';

    protected $description = 'Enable the Laravel scheduler on a Cloud site.';

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
                'type' => CloudWorker::TYPE_SCHEDULER,
                'size' => (string) $this->option('size'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scheduler enabled on site "%s" — running `%s` [%s]. Sync queued.',
            $site->name,
            $worker->effectiveCommand(),
            $worker->id,
        ));

        return self::SUCCESS;
    }
}
