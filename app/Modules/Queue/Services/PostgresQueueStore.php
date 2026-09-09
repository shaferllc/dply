<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\ClaimedJob;
use App\Modules\Queue\Support\QueueDepth;
use App\Modules\Queue\Support\QueueOperations;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Postgres-backed dply Queue store.
 *
 * Adapts Laravel's own `DatabaseQueue` model with four changes that only
 * matter once the store and the worker are separated by a network:
 *
 *  1. **One `visible_at` column** instead of `available_at` + `reserved_at`,
 *     so availability is a single indexable range rather than a disjunction —
 *     and an expired lease needs no sweeper to reclaim.
 *  2. **A fencing token per claim.** Every mutation matches on it, so a
 *     stalled worker's late ack cannot delete a newer consumer's reservation.
 *  3. **The server owns the lease.** `retry_after` is client config in
 *     `DatabaseQueue`; across a network two deploys of the same app would
 *     disagree about when a job expired.
 *  4. **Every timestamp comes from `now()` inside Postgres.** `DatabaseQueue`
 *     computes them in PHP, which is safe with one worker and wrong with a
 *     multi-node web tier whose clocks drift.
 *
 * Claiming is one statement, not a transaction spanning a select and an
 * update: holding row locks across two round trips at high concurrency is how
 * this design would fall over.
 */
class PostgresQueueStore implements QueueStore
{
    private const JOBS = 'dply_queue_jobs';

    private const FAILED = 'dply_queue_failed_jobs';

    public function __construct(
        private readonly QueuePayloadInspector $inspector,
        private readonly QueueUsageCounter $usage,
        private readonly QueueUsageMeter $meter,
        private readonly QueueJobDurations $durations,
    ) {}

    public function push(QueueNamespace $namespace, string $queue, string $payload, int $delaySeconds = 0): string
    {
        return $this->pushBulk($namespace, $queue, [$payload], $delaySeconds)[0];
    }

    public function pushBulk(QueueNamespace $namespace, string $queue, array $payloads, int $delaySeconds = 0): array
    {
        if ($payloads === []) {
            return [];
        }

        $delay = max(0, $delaySeconds);
        $rows = [];
        $ids = [];

        foreach ($payloads as $payload) {
            $meta = $this->inspector->inspect($payload);
            $id = (string) Str::ulid();
            $ids[] = $id;

            $rows[] = [
                'id' => $id,
                'namespace_id' => $namespace->id,
                'queue' => $this->normalizeQueue($queue),
                'payload' => $payload,
                'attempts' => 0,
                // Bound in SQL rather than PHP so a skewed web node cannot
                // enqueue something that is already late or absurdly early.
                'visible_at' => DB::raw("now() + interval '{$delay} seconds'"),
                'state' => 0,
                'job_uuid' => $meta['job_uuid'],
                'job_timeout' => $meta['job_timeout'],
                'job_max_tries' => $meta['job_max_tries'],
                'batch_id' => $meta['batch_id'],
                'display_name' => $meta['display_name'],
                'group_key' => $meta['group_key'] ?? null,
                'payload_bytes' => strlen($payload),
                'created_at' => DB::raw('now()'),
            ];
        }

        $this->connection()->table(self::JOBS)->insert($rows);

        // Counted after the insert committed, so neither series ever shows jobs
        // that were never enqueued. Both recorders swallow their own failures:
        // observability must not be able to fail a customer's push.
        //
        // Two of them, deliberately. The per-namespace counter feeds the
        // dashboard sparkline; the per-org meter is the original decision-9
        // rollup. Neither is a billing input any more — a namespace is priced by
        // capacity tier (docs/adr/managed-services-tier.md, decision 6) — so the
        // meter is now retained data collection rather than an invoice source.
        // Collapsing the two is follow-up work, not a merge-time call.
        $this->usage->record($namespace, count($rows));
        $this->meter->record($namespace, count($rows));

        // Dispatch is payload-bearing, so a 1 MiB job costs sixteen chunks
        // where a small one costs one (docs/adr/managed-queue-workers.md,
        // decision 6).
        $this->meter->recordOperations($namespace, QueueOperations::forPayloads($payloads));

        return $ids;
    }

    public function claim(
        QueueNamespace $namespace,
        array|string $queue,
        int $limit = 1,
        ?int $visibilitySeconds = null,
    ): array {
        $queues = $this->queueList($queue);
        $limit = max(1, $limit);
        $claimed = [];

        // The common case during a long poll is "nothing claimable", and the
        // loop below would spend one UPDATE per queue name to discover it —
        // three statements every poll interval for a worker on
        // `high,default,low`. One indexed EXISTS across all of them answers the
        // same question far more cheaply, and is a pure short-circuit: if it
        // says there is work, the claim still decides who gets it.
        if (! $this->hasClaimable($namespace, $queues)) {
            return [];
        }

        // Drain in priority order. One statement per queue rather than a
        // clever single ORDER BY across all of them — an expression like
        // array_position() cannot use the claim index, and an in-datacentre
        // round trip is cheaper than a sequential scan.
        foreach ($queues as $name) {
            if (count($claimed) >= $limit) {
                break;
            }

            $rows = $this->claimFrom($namespace, $name, $limit - count($claimed), $visibilitySeconds);

            foreach ($rows as $row) {
                $claimed[] = $row;
            }
        }

        // Starting a job is the second of the three operations a normal job
        // costs, and it carries the payload back to the worker, so it is
        // chunked the same way the dispatch was.
        if ($claimed !== []) {
            $this->meter->recordOperations($namespace, QueueOperations::forPayloads(
                array_map(static fn (ClaimedJob $job): string => $job->payload, $claimed),
            ));
        }

        return $claimed;
    }

    /**
     * Is anything claimable across these queues right now?
     *
     * Advisory only — it takes no lock, so a row it saw may be gone by the time
     * the claim runs, and a row it missed may appear a moment later. Both are
     * fine: the claim is still the authority, and the poll loop is going to ask
     * again. Being wrong here costs one wasted claim or one extra poll
     * interval, never a lost or double-delivered job.
     *
     * @param  list<string>  $queues
     */
    private function hasClaimable(QueueNamespace $namespace, array $queues): bool
    {
        return $this->connection()->table(self::JOBS)
            ->where('namespace_id', $namespace->id)
            ->whereIn('queue', $queues)
            ->where('visible_at', '<=', DB::raw('now()'))
            ->exists();
    }

    /**
     * @return list<ClaimedJob>
     */
    private function claimFrom(
        QueueNamespace $namespace,
        string $queue,
        int $limit,
        ?int $visibilitySeconds,
    ): array {
        // The lease is decided here, server-side, from the job's own declared
        // timeout — see QueuePayloadInspector::leaseSeconds(). Because the
        // clamp needs per-row `job_timeout`, it is expressed in SQL as a
        // GREATEST over the requested lease and the job's need.
        $requested = $this->inspector->leaseSeconds(null, $visibilitySeconds);
        $grace = (int) config('queue_service.reservation.lease_grace_seconds', 15);
        $max = (int) config('queue_service.reservation.max_visibility_seconds', 43200);

        $sql = '
            WITH locked AS (
                SELECT j.id, j.group_key, j.visible_at
                  FROM '.self::JOBS.' j
                 WHERE j.namespace_id = ?
                   AND j.queue = ?
                   AND j.visible_at <= now()
                   -- Per-group FIFO, half one: skip a group that already has a
                   -- job in flight. state = 1 with a future visible_at is the
                   -- in-flight marker the UPDATE below sets.
                   AND (
                       j.group_key IS NULL
                       OR NOT EXISTS (
                           SELECT 1
                             FROM '.self::JOBS.' g
                            WHERE g.namespace_id = j.namespace_id
                              AND g.queue = j.queue
                              AND g.group_key = j.group_key
                              AND g.state = 1
                              AND g.visible_at > now()
                       )
                   )
                 ORDER BY j.visible_at, j.id
                   FOR UPDATE SKIP LOCKED
                 LIMIT '.$limit.'
            ), claimed AS (
                -- Half two, and the one that is easy to miss: the NOT EXISTS
                -- above is evaluated before ANY row in this batch is reserved,
                -- so without this a single claim of limit N happily returns N
                -- jobs from the same group and orders nothing. Keep the first
                -- row per group; ungrouped rows are exempt because PARTITION BY
                -- treats all NULLs as one group.
                SELECT id
                  FROM (
                        SELECT id,
                               group_key,
                               row_number() OVER (PARTITION BY group_key ORDER BY visible_at, id) AS rn
                          FROM locked
                       ) ranked
                 WHERE group_key IS NULL OR rn = 1
            )
            UPDATE '.self::JOBS.' j
               SET visible_at = now() + (
                       -- Mirrors QueuePayloadInspector::leaseSeconds() exactly.
                       -- The CASE matters: a null or zero job_timeout means
                       -- "unknown", which must contribute nothing, NOT a bare
                       -- grace period — otherwise a short requested lease
                       -- would be silently lengthened to the grace value.
                       LEAST(
                           ?::int,
                           GREATEST(
                               ?::int,
                               CASE WHEN COALESCE(j.job_timeout, 0) > 0
                                    THEN j.job_timeout + ?::int
                                    ELSE 0
                               END
                           )
                       ) || \' seconds\'
                   )::interval,
                   reserved_at    = now(),
                   attempts       = j.attempts + 1,
                   reservation_id = gen_random_uuid(),
                   state          = 1
              FROM claimed c
             WHERE j.id = c.id
         RETURNING j.id, j.queue, j.payload, j.attempts, j.reservation_id,
                   j.visible_at, j.job_uuid, j.display_name
        ';

        $rows = $this->connection()->select($sql, [
            $namespace->id,
            $queue,
            $max,
            $requested,
            $grace,
        ]);

        return array_map(fn (object $row): ClaimedJob => new ClaimedJob(
            id: (string) $row->id,
            queue: (string) $row->queue,
            payload: (string) $row->payload,
            attempts: (int) $row->attempts,
            reservationId: (string) $row->reservation_id,
            visibleAt: (string) $row->visible_at,
            jobUuid: $row->job_uuid !== null ? (string) $row->job_uuid : null,
            displayName: $row->display_name !== null ? (string) $row->display_name : null,
        ), $rows);
    }

    public function ack(QueueNamespace $namespace, string $jobId, string $reservationId): bool
    {
        // DELETE ... RETURNING rather than a plain delete: the row is the only
        // place that knows when this job was claimed, and once it is gone the
        // duration is unrecoverable. One statement, not a select-then-delete.
        $deleted = $this->connection()->select(
            'DELETE FROM '.self::JOBS.'
              WHERE namespace_id = ? AND id = ? AND reservation_id = ?::uuid
          RETURNING queue, EXTRACT(EPOCH FROM (clock_timestamp() - reserved_at)) AS ran_for',
            [$namespace->id, $jobId, $reservationId],
        );

        if ($deleted !== []) {
            $this->recordDuration($namespace, $deleted);
            $this->meter->recordOperations($namespace, count($deleted));

            return true;
        }

        // Nothing deleted: either the job is already gone, or it is held under
        // a different reservation. Only the second is a problem — the first is
        // a lost ack response and a client retry, which is correct behaviour.
        return ! $this->existsUnderOtherReservation($namespace, $jobId, $reservationId);
    }

    public function ackBulk(QueueNamespace $namespace, array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        // One DELETE matching every (id, reservation_id) pair at once. The pair
        // must be matched as a unit — deleting `WHERE id IN (...) AND
        // reservation_id IN (...)` would let a stale handle for job A delete
        // job B under B's current reservation, which is exactly the fencing the
        // single-row path exists to enforce.
        $tuples = [];
        $bindings = [$namespace->id];
        foreach ($pairs as [$jobId, $reservationId]) {
            $tuples[] = '(?, ?::uuid)';
            $bindings[] = $jobId;
            $bindings[] = $reservationId;
        }

        $deleted = $this->connection()->select(
            'DELETE FROM '.self::JOBS.'
              WHERE namespace_id = ?
                AND (id, reservation_id) IN ('.implode(', ', $tuples).')
          RETURNING id, queue, EXTRACT(EPOCH FROM (clock_timestamp() - reserved_at)) AS ran_for',
            $bindings,
        );

        $this->recordDuration($namespace, $deleted);
        $this->meter->recordOperations($namespace, count($deleted));

        $gone = [];
        foreach ($deleted as $row) {
            $gone[(string) $row->id] = true;
        }

        // Anything not deleted is either already gone (a lost ack response and
        // a client retry — correct behaviour, report success) or held under a
        // newer reservation (report failure so the caller drops its copy).
        $survivors = $this->survivingIds($namespace, array_map(
            static fn (array $pair): string => $pair[0],
            array_filter($pairs, static fn (array $pair): bool => ! isset($gone[$pair[0]])),
        ));

        $result = [];
        foreach ($pairs as [$jobId]) {
            $result[$jobId] = isset($gone[$jobId]) || ! in_array($jobId, $survivors, true);
        }

        return $result;
    }

    /**
     * Which of these job ids still exist in the namespace.
     *
     * @param  list<string>  $jobIds
     * @return list<string>
     */
    private function survivingIds(QueueNamespace $namespace, array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }

        return $this->connection()->table(self::JOBS)
            ->where('namespace_id', $namespace->id)
            ->whereIn('id', $jobIds)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    public function release(QueueNamespace $namespace, string $jobId, string $reservationId, int $delaySeconds = 0): bool
    {
        $delay = max(0, $delaySeconds);

        $this->meter->recordOperations($namespace, 1);

        return $this->reserved($namespace, $jobId, $reservationId)->update([
            'visible_at' => DB::raw("now() + interval '{$delay} seconds'"),
            'reserved_at' => null,
            'reservation_id' => null,
            'state' => 0,
        ]) > 0;
    }

    public function fail(QueueNamespace $namespace, string $jobId, string $reservationId, ?string $exception = null): bool
    {
        $job = $this->reserved($namespace, $jobId, $reservationId)->first();

        if ($job === null) {
            return false;
        }

        $this->connection()->transaction(function () use ($namespace, $job, $exception): void {
            $this->connection()->table(self::FAILED)->upsert([[
                'id' => (string) Str::ulid(),
                'namespace_id' => $namespace->id,
                'job_uuid' => $job->job_uuid,
                'queue' => $job->queue,
                'payload' => $job->payload,
                'exception' => $exception,
                'display_name' => $job->display_name,
                'attempts' => $job->attempts,
                'failed_at' => DB::raw('now()'),
                'created_at' => DB::raw('now()'),
            ]], ['namespace_id', 'job_uuid'], ['exception', 'attempts', 'failed_at']);

            $this->connection()->table(self::JOBS)->where('id', $job->id)->delete();
        });

        return true;
    }

    public function heartbeat(QueueNamespace $namespace, string $jobId, string $reservationId, ?int $visibilitySeconds = null): bool
    {
        $lease = $this->inspector->leaseSeconds(null, $visibilitySeconds);

        // A long-running job records one of these periodically while it runs
        // — the visibility extension Laravel Cloud bills for too.
        $this->meter->recordOperations($namespace, 1);

        return $this->reserved($namespace, $jobId, $reservationId)->update([
            'visible_at' => DB::raw("now() + interval '{$lease} seconds'"),
        ]) > 0;
    }

    public function depth(QueueNamespace $namespace, ?string $queue = null): QueueDepth
    {
        $base = $this->connection()->table(self::JOBS)->where('namespace_id', $namespace->id);

        if ($queue !== null && $queue !== '') {
            $base->where('queue', $this->normalizeQueue($queue));
        }

        $row = (clone $base)
            ->selectRaw('
                count(*) FILTER (WHERE state = 0 AND visible_at <= now()) AS pending,
                count(*) FILTER (WHERE state = 0 AND visible_at >  now()) AS delayed,
                count(*) FILTER (WHERE state = 1)                         AS reserved
            ')
            ->first();

        return new QueueDepth(
            pending: (int) ($row->pending ?? 0),
            delayed: (int) ($row->delayed ?? 0),
            reserved: (int) ($row->reserved ?? 0),
        );
    }

    public function clear(QueueNamespace $namespace, string $queue): int
    {
        return $this->connection()->table(self::JOBS)
            ->where('namespace_id', $namespace->id)
            ->where('queue', $this->normalizeQueue($queue))
            ->delete();
    }

    public function purge(QueueNamespace $namespace): array
    {
        return [
            'jobs' => $this->connection()->table(self::JOBS)
                ->where('namespace_id', $namespace->id)
                ->delete(),
            'failed' => $this->connection()->table(self::FAILED)
                ->where('namespace_id', $namespace->id)
                ->delete(),
        ];
    }

    /**
     * Rows this caller is entitled to mutate: the right job, in the right
     * namespace, under the reservation it was actually issued. All three
     * conditions matter — the namespace check is the tenancy boundary and the
     * reservation check is the fencing token.
     */
    /**
     * Feed completed-job timings to the fleet autoscaler.
     *
     * The elapsed time is read with `clock_timestamp()`, not `now()`.
     * Postgres freezes `now()` at the start of the enclosing transaction, so
     * a duration measured with it is zero whenever the ack shares a
     * transaction with anything else — every job would look instant and every
     * backlog free to drain. This is the one place in the store that wants
     * wall-clock rather than transaction time.
     *
     * Best-effort by construction: the job is already acked and the client is
     * already being told so. A cache that is down must cost a measurement,
     * never a duplicate delivery.
     *
     * @param  list<object>  $rows  DELETE ... RETURNING rows
     */
    private function recordDuration(QueueNamespace $namespace, array $rows): void
    {
        try {
            foreach ($rows as $row) {
                $ranFor = $row->ran_for ?? null;

                if ($ranFor === null) {
                    continue;
                }

                $this->durations->record($namespace, (string) ($row->queue ?? ''), (float) $ranFor);
            }
        } catch (\Throwable) {
            // Intentionally swallowed — see the docblock.
        }
    }

    private function reserved(QueueNamespace $namespace, string $jobId, string $reservationId): Builder
    {
        return $this->connection()->table(self::JOBS)
            ->where('namespace_id', $namespace->id)
            ->where('id', $jobId)
            ->where('reservation_id', $reservationId);
    }

    private function existsUnderOtherReservation(QueueNamespace $namespace, string $jobId, string $reservationId): bool
    {
        return $this->connection()->table(self::JOBS)
            ->where('namespace_id', $namespace->id)
            ->where('id', $jobId)
            ->where(function ($query) use ($reservationId): void {
                $query->whereNull('reservation_id')->orWhere('reservation_id', '!=', $reservationId);
            })
            ->exists();
    }

    /**
     * @param  list<string>|string  $queue
     * @return list<string>
     */
    private function queueList(array|string $queue): array
    {
        $names = is_array($queue) ? $queue : explode(',', $queue);

        $normalized = [];
        foreach ($names as $name) {
            $clean = $this->normalizeQueue((string) $name);
            if (! in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        return $normalized === [] ? ['default'] : $normalized;
    }

    private function normalizeQueue(string $queue): string
    {
        $clean = trim($queue);

        return $clean === '' ? 'default' : mb_substr($clean, 0, 128);
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection('dply_queue');
    }
}
