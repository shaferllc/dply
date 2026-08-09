<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Http\Controllers\ServerlessQueueWakeController;
use App\Models\Site;
use App\Modules\Serverless\Console\ServerlessTickCommand;
use App\Modules\Serverless\Jobs\ServerlessQueueSlotJob;
use Illuminate\Support\Facades\Cache;

/**
 * Drives queue work on a serverless function.
 *
 * A function has no long-running process, so there is nowhere to run
 * `queue:work` as a daemon. The old stand-in was a once-a-minute cron tick
 * that drained for 50s — one serial worker, and up to 60s before a freshly
 * dispatched job was even looked at.
 *
 * The pump replaces that with concurrency. It holds up to `max_concurrency`
 * invocations of the function open at once, each running a bounded
 * `queue:work` drain (a "slot"). Each slot reports what it drained and what
 * is left; while work remains a slot re-invokes immediately instead of
 * waiting for a cron edge, and a deep backlog opens more slots up to the
 * ceiling. When the queue empties, slots close and the function costs
 * nothing until the next wake.
 *
 * Waking is push-based: the first-party Laravel package pings
 * {@see ServerlessQueueWakeController}
 * from a `JobQueued` hook. {@see ServerlessTickCommand}
 * also wakes the pump every minute as a safety net, so an app without the
 * package (or one whose ping was lost) still drains — just with the old
 * latency rather than none at all.
 *
 * Slot accounting lives in the cache, not the database: it is hot,
 * short-lived, and self-healing. The counter carries a TTL so a slot lost to
 * a crashed worker cannot strand capacity forever.
 */
final class ServerlessQueuePump
{
    /** Hard ceiling on per-site concurrency, whatever the site asks for. */
    public const MAX_CONCURRENCY_CEILING = 50;

    public const DEFAULT_MAX_CONCURRENCY = 4;

    /** Seconds one slot may drain. Kept under the function's own timeout. */
    public const DEFAULT_SLOT_MAX_TIME = 50;

    /**
     * Slots opened per wake. Concurrency ramps rather than stampeding: a
     * slot that finds a deep backlog wakes the pump again, so depth grows
     * geometrically toward the ceiling only while the backlog justifies it.
     */
    public const WAKE_RAMP = 2;

    /** Backlog above which a finishing slot asks for more concurrency. */
    public const RAMP_THRESHOLD = 5;

    /**
     * A leaked slot reservation self-heals after this long. Comfortably
     * longer than the longest slot, short enough that a crash does not
     * wedge the site's queue until someone notices.
     */
    private const SLOT_TTL_MINUTES = 20;

    /**
     * Effective pump settings for a site.
     *
     * @return array{enabled: bool, max_concurrency: int, queue: string, slot_max_time: int, slot_max_jobs: int}
     */
    public function config(Site $site): array
    {
        $cfg = $site->serverlessConfig();
        $pump = is_array($cfg['queue'] ?? null) ? $cfg['queue'] : [];

        // Falls back to the legacy background_enabled toggle so sites that
        // opted into background processing before the pump existed keep
        // draining without anyone re-enabling anything.
        $enabled = array_key_exists('enabled', $pump)
            ? (bool) $pump['enabled']
            : (bool) ($cfg['background_enabled'] ?? false);

        $max = (int) ($pump['max_concurrency'] ?? self::DEFAULT_MAX_CONCURRENCY);

        return [
            'enabled' => $enabled,
            'max_concurrency' => max(1, min(self::MAX_CONCURRENCY_CEILING, $max)),
            'queue' => trim((string) ($pump['queue'] ?? '')),
            'slot_max_time' => max(1, min(880, (int) ($pump['slot_max_time'] ?? self::DEFAULT_SLOT_MAX_TIME))),
            'slot_max_jobs' => max(0, (int) ($pump['slot_max_jobs'] ?? 0)),
        ];
    }

    /**
     * Open slots for a site that has (or may have) work waiting.
     *
     * Safe to call from anywhere, as often as you like — it is bounded by
     * the concurrency ceiling and returns how many slots it actually opened.
     */
    public function wake(Site $site, int $ramp = self::WAKE_RAMP): int
    {
        if (! $this->config($site)['enabled']) {
            return 0;
        }

        $opened = 0;
        while ($opened < max(1, $ramp) && $this->tryOpenSlot($site)) {
            ServerlessQueueSlotJob::dispatch($site->id);
            $opened++;
        }

        return $opened;
    }

    /**
     * Reserve one unit of concurrency, or report that the site is already at
     * its ceiling. Serialized with a lock so two simultaneous wakes cannot
     * both read the same count and overshoot.
     */
    public function tryOpenSlot(Site $site): bool
    {
        $max = $this->config($site)['max_concurrency'];
        $key = $this->slotKey($site);

        $granted = Cache::lock($key.':lock', 5)->block(3, function () use ($key, $max): bool {
            $active = max(0, (int) Cache::get($key, 0));
            if ($active >= $max) {
                return false;
            }

            Cache::put($key, $active + 1, now()->addMinutes(self::SLOT_TTL_MINUTES));

            return true;
        });

        return (bool) $granted;
    }

    /**
     * Release a unit of concurrency. Called from a slot's `finally`, so a
     * slot that throws still gives its capacity back.
     */
    public function closeSlot(Site $site): void
    {
        $key = $this->slotKey($site);

        Cache::lock($key.':lock', 5)->block(3, function () use ($key): void {
            $active = max(0, (int) Cache::get($key, 0) - 1);

            if ($active === 0) {
                Cache::forget($key);

                return;
            }

            Cache::put($key, $active, now()->addMinutes(self::SLOT_TTL_MINUTES));
        });
    }

    public function activeSlots(Site $site): int
    {
        return max(0, (int) Cache::get($this->slotKey($site), 0));
    }

    /** Drop all slot accounting for a site — used when the pump is disabled. */
    public function reset(Site $site): void
    {
        Cache::forget($this->slotKey($site));
    }

    private function slotKey(Site $site): string
    {
        return 'serverless:queue:slots:'.$site->id;
    }
}
