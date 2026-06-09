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

        // Down monitors back off to the slower down-interval: a site stuck
        // failing is re-probed every down_check_interval_minutes instead of
        // every cycle. A grace of half the base cadence absorbs cron/dispatch
        // jitter so the slow cadence lands on the intended cycle, not the next.
        $downInterval = max(1, (int) config('site_uptime.down_check_interval_minutes', 15));
        $baseInterval = max(1, (int) config('site_uptime.check_interval_minutes', 5));
        $cutoff = now()->subMinutes($downInterval)->addSeconds((int) round($baseInterval * 60 / 2));

        SiteUptimeMonitor::query()
            // Skip only monitors that are down AND were probed recently; healthy,
            // unknown, never-checked, and due-for-recheck monitors all run.
            ->where(fn ($q) => $q
                ->where('last_ok', '!=', false)
                ->orWhereNull('last_ok')
                ->orWhereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', $cutoff))
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
