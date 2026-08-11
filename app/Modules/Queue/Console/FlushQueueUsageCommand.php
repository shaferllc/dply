<?php

declare(strict_types=1);

namespace App\Modules\Queue\Console;

use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Models\QueueNamespaceUsageDaily;
use App\Modules\Queue\Services\QueueUsageCounter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Folds the Redis push counters into `dply_queue_usage_daily` so the dashboard
 * has a throughput series that survives a Redis eviction. Runs hourly.
 *
 * Driven from the namespace table rather than by SCANning Redis for keys. Two
 * reasons: SCAN's return shape differs between the phpredis and predis clients,
 * and this Redis is shared with dply's own Horizon queues — walking its keyspace
 * hourly to find our own is both fragile and rude. The namespace list is a
 * small indexed table and tells us exactly which keys can exist.
 *
 * Upserts the running total rather than adding a delta: the Redis key holds the
 * day's cumulative count, so re-running within a day is idempotent and a missed
 * hour self-heals on the next pass.
 *
 * Observational only — nothing here reaches an invoice
 * (docs/adr/managed-services-tier.md, decision 6).
 */
class FlushQueueUsageCommand extends Command
{
    protected $signature = 'dply:queue:flush-usage';

    protected $description = 'Fold Redis queue push counters into the daily usage rollup.';

    public function handle(): int
    {
        // Today and yesterday: an hourly run straddling midnight must still
        // finalise the day that just ended. Bounded by the counter's 48h TTL.
        $dates = [now()->toDateString(), now()->subDay()->toDateString()];

        $flushed = 0;
        $failed = 0;

        QueueNamespace::query()
            ->select(['id'])
            ->cursor()
            ->each(function (QueueNamespace $namespace) use ($dates, &$flushed, &$failed): void {
                foreach ($dates as $date) {
                    try {
                        if ($this->flush((string) $namespace->id, $date)) {
                            $flushed++;
                        }
                    } catch (Throwable $e) {
                        // One unreadable counter must not abandon the sweep for
                        // every other namespace.
                        $failed++;
                        $this->warn("Could not flush {$namespace->id} for {$date}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Flushed {$flushed} queue usage counter(s)".($failed > 0 ? ", {$failed} failed." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function flush(string $namespaceId, string $date): bool
    {
        $count = (int) Redis::get(QueueUsageCounter::key($namespaceId, $date));

        if ($count < 1) {
            return false;
        }

        QueueNamespaceUsageDaily::query()->updateOrCreate(
            ['namespace_id' => $namespaceId, 'usage_date' => $date],
            ['jobs_pushed' => $count],
        );

        return true;
    }
}
