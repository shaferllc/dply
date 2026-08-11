<?php

declare(strict_types=1);

namespace App\Modules\Queue\Contracts;

use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\ClaimedJob;
use App\Modules\Queue\Support\QueueDepth;

/**
 * Storage behind a dply Queue namespace.
 *
 * `PostgresQueueStore` is the only implementation today. A Redis store may
 * follow, but note the scope: its value is not throughput, it is that a real
 * RESP-protocol endpoint would make Horizon, `WithoutOverlapping`,
 * `ShouldBeUnique`, batches, and the cache all work unmodified. That is a
 * much larger job than a second class behind this interface, and should not
 * be sized as a storage swap (docs/adr/dply-queue.md).
 *
 * Every mutation of a claimed job takes the `reservationId` it was issued.
 * That is the fencing token, and it is what stops a stalled worker's late ack
 * from deleting a different worker's reservation.
 */
interface QueueStore
{
    /**
     * Enqueue one job. Returns its id.
     *
     * @param  int  $delaySeconds  0 for immediately claimable
     */
    public function push(QueueNamespace $namespace, string $queue, string $payload, int $delaySeconds = 0): string;

    /**
     * Enqueue several jobs in one round trip.
     *
     * @param  list<string>  $payloads
     * @return list<string> ids, in the order given
     */
    public function pushBulk(QueueNamespace $namespace, string $queue, array $payloads, int $delaySeconds = 0): array;

    /**
     * Claim up to `$limit` jobs, making them invisible for the lease.
     *
     * Claiming is the only operation that issues a reservation id, and it
     * increments `attempts` — matching `DatabaseQueue::markJobAsReserved`, so
     * a worker that vanishes still burns an attempt rather than looping
     * forever.
     *
     * @param  list<string>|string  $queue  a priority-ordered list drains in order
     * @return list<ClaimedJob>
     */
    public function claim(
        QueueNamespace $namespace,
        array|string $queue,
        int $limit = 1,
        ?int $visibilitySeconds = null,
    ): array;

    /**
     * Delete a completed job. Idempotent: a job that is already gone is a
     * success, because the common cause is a lost ack response and the client
     * retrying is correct behaviour.
     *
     * Returns false only when the job still exists but is held under a
     * different reservation — the caller must then abandon its local copy.
     */
    public function ack(QueueNamespace $namespace, string $jobId, string $reservationId): bool;

    /**
     * Delete several completed jobs in one statement.
     *
     * The point is throughput: acking per job costs one HTTP round trip and one
     * DELETE each, and at a worker's pace that is the drain ceiling long before
     * Postgres is troubled. Same semantics as {@see ack()} applied per pair.
     *
     * @param  list<array{0: string, 1: string}>  $pairs  [jobId, reservationId]
     * @return array<string, bool> keyed by job id — false means the job is held
     *                             under a different reservation
     */
    public function ackBulk(QueueNamespace $namespace, array $pairs): array;

    /**
     * Return a job to the queue, optionally after a delay.
     *
     * Returns false when the reservation no longer matches — unlike ack, this
     * is NOT idempotent-on-missing, because "the job is gone" and "someone
     * else owns it now" need different client behaviour.
     */
    public function release(QueueNamespace $namespace, string $jobId, string $reservationId, int $delaySeconds = 0): bool;

    /**
     * Move a job to the failed table. Returns false if the reservation no
     * longer matches.
     */
    public function fail(QueueNamespace $namespace, string $jobId, string $reservationId, ?string $exception = null): bool;

    /** Extend a live reservation, for a job that is still running. */
    public function heartbeat(QueueNamespace $namespace, string $jobId, string $reservationId, ?int $visibilitySeconds = null): bool;

    public function depth(QueueNamespace $namespace, ?string $queue = null): QueueDepth;

    /** Drop every job in a queue. Returns how many were removed. */
    public function clear(QueueNamespace $namespace, string $queue): int;

    /**
     * Drop everything belonging to a namespace — every queue, plus its failed
     * jobs. For teardown only.
     *
     * The job tables live on a separate connection from the namespace row, so
     * no foreign key can cascade this. Deleting a namespace without it would
     * leave rows nothing can ever reach or bill for.
     *
     * @return array{jobs: int, failed: int}
     */
    public function purge(QueueNamespace $namespace): array;
}
