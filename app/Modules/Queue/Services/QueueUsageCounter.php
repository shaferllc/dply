<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Counts jobs pushed per namespace per day, in Redis, for the dashboard.
 *
 * Deliberately cheap and deliberately lossy. Under docs/adr/dply-queue.md
 * decision 9 this was going to be billing-grade, which would have meant
 * exactly-once-ish accounting because the number landed on an invoice. Pricing
 * moved to per-namespace capacity tiers instead, so the strongest claim this
 * needs to support is "roughly how busy was this queue last week". A dropped
 * INCRBY costs a few pixels of sparkline.
 *
 * That difference is why every method here swallows its errors: the counter
 * must never be able to fail a customer's push. A queue that rejects jobs
 * because our metrics backend hiccuped would be a strictly worse product than
 * one with a gap in a chart.
 *
 * See docs/adr/managed-services-tier.md, decision 6.
 */
final class QueueUsageCounter
{
    /** Keys outlive the daily flush by a wide margin so a late sweep still finds them. */
    private const TTL_SECONDS = 172800; // 48h

    public function record(QueueNamespace $namespace, int $jobs = 1): void
    {
        if ($jobs < 1) {
            return;
        }

        try {
            $key = self::key((string) $namespace->id, now()->toDateString());

            Redis::incrby($key, $jobs);
            Redis::expire($key, self::TTL_SECONDS);
        } catch (Throwable) {
            // Intentionally silent. See the class docblock: observability must
            // not be able to break the data plane.
        }
    }

    /**
     * `dq:usage:{namespace}:{Y-m-d}`
     *
     * The flush reconstructs these from the namespace table rather than
     * scanning for them, so this is the single definition of the key shape on
     * both the write and read sides.
     */
    public static function key(string $namespaceId, string $date): string
    {
        return 'dq:usage:'.$namespaceId.':'.$date;
    }
}
