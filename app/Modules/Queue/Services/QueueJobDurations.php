<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Support\Facades\Cache;

/**
 * How long jobs on a queue actually take.
 *
 * This is the number that turns the autoscaler from a guess into a
 * measurement: a thousand pending jobs is meaningless until you know whether
 * each takes a millisecond or a minute. dply hosts the store, so it sees the
 * claim and the ack and can time the gap between them without the app
 * reporting anything.
 *
 * Kept as an exponentially-weighted average in the cache, not a table. A row
 * per completed job would be a second churn table beside the jobs one, for a
 * number only ever read once a minute by a tick. The tradeoff is that a cache
 * flush loses the estimate — which is why {@see FleetReconciler} mirrors the
 * last value onto the fleet, and why the autoscaler has its own fallback:
 * losing this must degrade sizing, never break it.
 */
class QueueJobDurations
{
    /** Recent samples dominate, but one slow job cannot move the fleet alone. */
    private const ALPHA = 0.2;

    /** Long enough that a quiet queue keeps its estimate overnight. */
    private const TTL_SECONDS = 86_400;

    /**
     * A job that "took" longer than this was almost certainly redelivered
     * after its worker died, not genuinely running — counting it would size
     * the fleet for work that never happened.
     */
    private const MAX_CREDIBLE_SECONDS = 3_600;

    public function record(QueueNamespace $namespace, string $queue, float $seconds): void
    {
        if ($seconds <= 0 || $seconds > self::MAX_CREDIBLE_SECONDS) {
            return;
        }

        $key = $this->key($namespace->id, $queue);
        $current = Cache::get($key);

        $average = is_array($current) && isset($current['avg'])
            ? (self::ALPHA * $seconds) + ((1 - self::ALPHA) * (float) $current['avg'])
            : $seconds;

        Cache::put($key, [
            'avg' => round($average, 4),
            'samples' => (int) (is_array($current) ? ($current['samples'] ?? 0) : 0) + 1,
        ], self::TTL_SECONDS);
    }

    /** Mean seconds per job, or null when this queue has never been measured. */
    public function average(QueueNamespace $namespace, string $queue): ?float
    {
        $value = Cache::get($this->key($namespace->id, $queue));

        return is_array($value) && isset($value['avg']) ? (float) $value['avg'] : null;
    }

    public function samples(QueueNamespace $namespace, string $queue): int
    {
        $value = Cache::get($this->key($namespace->id, $queue));

        return is_array($value) ? (int) ($value['samples'] ?? 0) : 0;
    }

    private function key(string $namespaceId, string $queue): string
    {
        return 'dply:queue:duration:'.$namespaceId.':'.($queue === '' ? 'default' : $queue);
    }
}
