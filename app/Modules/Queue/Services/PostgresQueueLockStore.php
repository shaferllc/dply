<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomic named locks for a namespace.
 *
 * Backs `ShouldBeUnique`, `WithoutOverlapping`, and `RateLimited`, which
 * Laravel implements against the cache rather than the queue — and which
 * therefore do nothing at all on a function, whose default cache store is
 * per-invocation.
 *
 * Acquisition is one statement. The `ON CONFLICT … WHERE expires_at <= now()`
 * form means the database decides the winner: either the insert lands, or an
 * expired row is taken over, or nothing is returned and the caller lost. There
 * is no read-then-write window for two invocations to race through, which is
 * the entire point — a lock with a check-then-set gap is not a lock.
 */
class PostgresQueueLockStore
{
    private const TABLE = 'dply_queue_locks';

    /** Nothing may hold a lock forever; a crashed holder must not wedge a queue. */
    private const MAX_TTL_SECONDS = 86400;

    /**
     * Take the lock, or report that someone else holds it.
     *
     * @param  string  $owner  the holder's token, required to release
     */
    public function acquire(QueueNamespace $namespace, string $name, string $owner, int $seconds): bool
    {
        $ttl = max(1, min(self::MAX_TTL_SECONDS, $seconds));

        $rows = $this->connection()->select('
            INSERT INTO '.self::TABLE.' (id, namespace_id, name, owner, expires_at, created_at)
            VALUES (?, ?, ?, ?, now() + (?::int || \' seconds\')::interval, now())
            ON CONFLICT (namespace_id, name) DO UPDATE
               SET owner      = EXCLUDED.owner,
                   expires_at = EXCLUDED.expires_at,
                   created_at = now()
             WHERE '.self::TABLE.'.expires_at <= now()
         RETURNING owner
        ', [(string) Str::ulid(), $namespace->id, $name, $owner, $ttl]);

        // No row means the conflict target existed and had NOT expired, so the
        // WHERE suppressed the update: someone else holds it.
        return $rows !== [];
    }

    /**
     * Release a lock, but only if this owner still holds it.
     *
     * The owner check is a fencing token. Without it, a process whose lock
     * expired mid-run would release the lock a different process has since
     * acquired — and both would then believe they hold it, which is precisely
     * the double-execution `WithoutOverlapping` exists to prevent.
     */
    public function release(QueueNamespace $namespace, string $name, string $owner): bool
    {
        return $this->connection()->table(self::TABLE)
            ->where('namespace_id', $namespace->id)
            ->where('name', $name)
            ->where('owner', $owner)
            ->delete() > 0;
    }

    /** Drop a lock regardless of holder — the operator escape hatch. */
    public function forceRelease(QueueNamespace $namespace, string $name): void
    {
        $this->connection()->table(self::TABLE)
            ->where('namespace_id', $namespace->id)
            ->where('name', $name)
            ->delete();
    }

    /** The current holder, or null when the lock is free or expired. */
    public function owner(QueueNamespace $namespace, string $name): ?string
    {
        $row = $this->connection()->table(self::TABLE)
            ->where('namespace_id', $namespace->id)
            ->where('name', $name)
            ->where('expires_at', '>', now())
            ->first();

        return $row === null ? null : (string) $row->owner;
    }

    /**
     * Remove expired rows.
     *
     * Housekeeping only — an expired lock is already ignorable, because both
     * `acquire()` and `owner()` compare against `now()`. This just stops the
     * table growing without bound.
     */
    public function pruneExpired(): int
    {
        return $this->connection()->table(self::TABLE)
            ->where('expires_at', '<=', now()->subHour())
            ->delete();
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection('dply_queue');
    }
}
