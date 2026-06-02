<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Models\SiteUptimeMonitor;
use Illuminate\Console\Command;

class DispatchSiteUptimeChecksCommand extends Command
{
    protected $signature = 'dply:dispatch-site-uptime-checks';

    protected $description = 'Queue uptime monitor probes for configured monitors.';

    public function handle(): int
    {
        if (! config('site_uptime.enabled', true)) {
            $this->components->info('Site uptime checks are disabled.');

            return self::SUCCESS;
        }

        $count = 0;

        SiteUptimeMonitor::query()
            ->get(['id', 'probe_worker'])
            ->each(function (SiteUptimeMonitor $monitor) use (&$count): void {
                RunSiteUptimeMonitorCheckJob::dispatch($monitor->id)
                    ->onQueue(RunSiteUptimeMonitorCheckJob::queueForMonitor($monitor));
                $count++;
            });

        $this->components->info("Queued {$count} site uptime check(s).");

        return self::SUCCESS;
    }
}
