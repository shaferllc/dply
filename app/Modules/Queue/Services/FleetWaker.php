<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts a sleeping fleet's first worker the moment a job lands.
 *
 * This is the difference between "scales to zero" and "is asleep". The tick
 * is a one-minute cron, so without this the first job dispatched to an idle
 * queue would wait up to sixty seconds — and every demo of the product is
 * exactly that dispatch.
 *
 * It runs on the push request, which is a hard constraint: the work here is
 * one indexed lookup and, at most, one worker start, and it can never fail a
 * push. A queue that accepted a job is correct even if nothing woke; the tick
 * is the backstop.
 */
class FleetWaker
{
    /**
     * One wake attempt per fleet per this window.
     *
     * A burst of a thousand dispatches is a thousand push requests, and
     * without this every one of them would try to start a worker. The
     * autoscaler sizes for the backlog a second later anyway.
     */
    private const THROTTLE_SECONDS = 5;

    public function __construct(private readonly FleetReconciler $reconciler) {}

    /** Wake the fleet draining `$queue`, if there is one and it is asleep. */
    public function wake(QueueNamespace $namespace, string $queue): bool
    {
        try {
            $fleet = ManagedQueueFleet::query()
                ->where('namespace_id', $namespace->id)
                ->where('queue', $queue === '' ? 'default' : $queue)
                ->where('status', ManagedQueueFleet::STATUS_ACTIVE)
                ->first();

            if (! $fleet instanceof ManagedQueueFleet || ! $fleet->wakesOnPush()) {
                return false;
            }

            // add() is atomic: the first pusher in the window wins and the
            // rest return immediately, without a queue-depth query between
            // them and the response.
            if (! Cache::add($this->throttleKey($fleet), true, self::THROTTLE_SECONDS)) {
                return false;
            }

            return $this->reconciler->wake($fleet);
        } catch (Throwable $e) {
            // Never fail a push because a worker could not be started. The
            // job is safely enqueued; the tick will size the fleet.
            Log::warning('queue.fleet.wake_failed', [
                'namespace_id' => $namespace->id,
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function throttleKey(ManagedQueueFleet $fleet): string
    {
        return 'dply:queue:fleet-wake:'.$fleet->id;
    }
}
