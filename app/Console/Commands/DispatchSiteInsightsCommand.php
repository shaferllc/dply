<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunSiteInsightsJob;
use App\Models\Site;
use Illuminate\Console\Command;

class DispatchSiteInsightsCommand extends Command
{
    protected $signature = 'dply:dispatch-site-insights';

    protected $description = 'Queue insight runs for active webserver sites.';

    public function handle(): int
    {
        $count = 0;

        Site::query()
            ->whereIn('status', Site::webserverActiveStatuses(), 'and', false)
            ->pluck('id')
            ->each(function (string $id) use (&$count): void {
                RunSiteInsightsJob::dispatch($id);
                $count++;
            });

        $this->components->info("Queued {$count} site insight run(s).");

        return self::SUCCESS;
    }
}
