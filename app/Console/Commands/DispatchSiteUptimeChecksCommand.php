<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Models\SiteUptimeMonitor;
use App\Services\Sites\UptimeProbeWorkerResolver;
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

        // A monitor not probed in this long is treated as wedged: its probe
        // worker's queue isn't draining, so this cycle's check is routed onto the
        // central `default` queue (always drained by the app Horizon) so a dead
        // regional worker can't silently freeze monitoring. Never falls below the
        // down cadence, so a normally backed-off down monitor isn't misread.
        $stallMinutes = max(
            (int) config('site_uptime.probe_stall_minutes', 30),
            $downInterval * 2,
        );
        $stallCutoff = now()->subMinutes($stallMinutes);
        $fellBack = 0;

        SiteUptimeMonitor::query()
            // Skip only monitors that are down AND were probed recently; healthy,
            // unknown, never-checked, and due-for-recheck monitors all run.
            ->where(fn ($q) => $q
                ->where('last_ok', '!=', false)
                ->orWhereNull('last_ok')
                ->orWhereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', $cutoff))
            ->get(['id', 'probe_worker', 'last_checked_at'])
            ->each(function (SiteUptimeMonitor $monitor) use (&$count, &$fellBack, $stallCutoff): void {
                // Wedged when we've dispatched before (last_checked_at is set) yet
                // it hasn't advanced past the stall window. A never-checked monitor
                // uses its normal worker — there's no evidence its queue is stuck.
                $wedged = $monitor->last_checked_at !== null
                    && $monitor->last_checked_at->lessThanOrEqualTo($stallCutoff);

                $queue = $wedged
                    ? UptimeProbeWorkerResolver::FALLBACK_QUEUE
                    : RunSiteUptimeMonitorCheckJob::queueForMonitor($monitor);

                RunSiteUptimeMonitorCheckJob::dispatch($monitor->id)->onQueue($queue);
                $count++;
                if ($wedged) {
                    $fellBack++;
                }
            });

        $suffix = $fellBack > 0 ? " ({$fellBack} via central fallback — a probe worker looks wedged)" : '';
        $this->components->info("Queued {$count} site uptime check(s){$suffix}.");

        return self::SUCCESS;
    }
}
