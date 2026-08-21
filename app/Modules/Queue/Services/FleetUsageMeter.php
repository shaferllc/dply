<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Models\QueueUsageDaily;
use Illuminate\Support\Carbon;

/**
 * Rolls managed worker time into the daily usage rows an invoice reads.
 *
 * Worker rows are the evidence, so unlike the queue counters this is metered
 * from the database rather than from Redis — a worker's life is durable, and
 * nothing about it is lost if a cache is flushed.
 *
 * Accrual is `[from, now]` where `from` is `billed_through_at` if this worker
 * has been metered before and `started_at` if it has not. That watermark is
 * what makes an always-on Pro worker billable: waiting for `stopped_at` would
 * mean a worker that runs for a month is invoiced in the month it dies.
 *
 * The unit is MiB-seconds, split by compute class. A second alone cannot
 * price a 256 MiB worker and an 8 GiB one, and the split is where Pro's
 * premium over the equivalent Flex size is applied.
 */
class FleetUsageMeter
{
    /**
     * @return array{workers: int, flex_mib_seconds: int, pro_mib_seconds: int}
     */
    public function roll(?Carbon $asOf = null): array
    {
        $now = $asOf ?? now();
        $totals = ['workers' => 0, 'flex_mib_seconds' => 0, 'pro_mib_seconds' => 0];

        // Anything with unmetered time: still running, or stopped but never
        // settled into a usage row.
        $workers = ManagedQueueWorker::query()
            ->whereNull('billed_at')
            ->whereNotNull('started_at')
            ->with('fleet')
            ->get();

        /** @var array<string, array{flex: int, pro: int}> $buckets keyed org|day */
        $buckets = [];

        foreach ($workers as $worker) {
            $fleet = $worker->fleet;

            if (! $fleet instanceof ManagedQueueFleet) {
                continue;
            }

            $from = $worker->billed_through_at ?? $worker->started_at;
            $to = $worker->stopped_at ?? $now;

            if (! $from instanceof Carbon || $to->lessThanOrEqualTo($from)) {
                // Nothing new to bill. A stopped worker with no elapsed time
                // is still closed out, so it stops being re-read every hour.
                $this->close($worker, $now);

                continue;
            }

            $seconds = (int) $from->diffInSeconds($to, absolute: true);
            $mibSeconds = $seconds * max(0, $worker->memory_mib);

            // Attributed to the day the accrual ended. A worker spanning
            // midnight lands its whole slice on one day; the alternative is
            // splitting every accrual across day boundaries for a rounding
            // difference no invoice would notice.
            $key = $fleet->organization_id.'|'.$to->utc()->toDateString();
            $buckets[$key] ??= ['flex' => 0, 'pro' => 0];

            if ($fleet->class === ManagedQueueFleet::CLASS_PRO) {
                $buckets[$key]['pro'] += $mibSeconds;
                $totals['pro_mib_seconds'] += $mibSeconds;
            } else {
                $buckets[$key]['flex'] += $mibSeconds;
                $totals['flex_mib_seconds'] += $mibSeconds;
            }

            $totals['workers']++;

            $this->close($worker, $to);
        }

        foreach ($buckets as $key => $amounts) {
            [$organizationId, $day] = explode('|', $key, 2);

            $row = QueueUsageDaily::query()->firstOrNew([
                'organization_id' => $organizationId,
                'day' => $day,
                'source' => QueueUsageDaily::SOURCE_COUNTER,
            ]);

            // Incremented, not overwritten: unlike the push counters — which
            // hold a running daily total and can be written absolutely — each
            // pass here reports only the slice since the last watermark.
            $row->flex_mib_seconds = (int) $row->flex_mib_seconds + $amounts['flex'];
            $row->pro_mib_seconds = (int) $row->pro_mib_seconds + $amounts['pro'];
            $row->jobs_pushed = (int) $row->jobs_pushed;
            $row->save();
        }

        return $totals;
    }

    /**
     * Advance the watermark, and close the row for good once the worker has
     * stopped — a stopped, fully-metered worker must never be read again.
     */
    private function close(ManagedQueueWorker $worker, Carbon $through): void
    {
        $attributes = ['billed_through_at' => $through];

        if ($worker->stopped_at !== null) {
            $attributes['billed_at'] = now();
        }

        $worker->forceFill($attributes)->save();
    }
}
