<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckSiteUrlHealthJob;
use App\Models\Site;
use Illuminate\Console\Command;

class DispatchSiteUrlHealthChecksCommand extends Command
{
    protected $signature = 'dply:dispatch-site-url-health-checks';

    protected $description = 'Queue URL health checks for active webserver sites with domains.';

    public function handle(): int
    {
        if (! config('dply.site_health_check_enabled', true)) {
            $this->components->info('Site URL health checks are disabled.');

            return self::SUCCESS;
        }

        $count = 0;

        Site::query()
            ->whereIn('status', Site::webserverActiveStatuses(), 'and', false)
            ->whereHas('domains')
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                CheckSiteUrlHealthJob::dispatch($id);
                $count++;
            });

        $this->components->info("Queued {$count} site URL health check(s).");

        return self::SUCCESS;
    }
}
