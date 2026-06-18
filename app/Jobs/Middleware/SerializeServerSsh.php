<?php

namespace App\Jobs\Middleware;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Serialize SSH-bound jobs per target server, so many sites on one box don't
 * open concurrent SSH sessions and saturate sshd. Jobs that share a server id
 * run one-at-a-time; a job that can't get the lock is RELEASED back to the
 * queue and retried after {@see $releaseAfter}s (it does not fail).
 *
 * Reusable: add to any SSH job's `middleware()` with the target server id.
 *
 * IMPORTANT — the consuming job must pair this with:
 *   - `maxExceptions = 1` (or similar): a contended release is not an exception,
 *     but a real handler error must fail fast instead of being retried; without
 *     a cap, a broken push would re-SSH until retryUntil.
 *   - `retryUntil()`: bound how long a job waits for the SSH slot. Releases from
 *     this middleware count as attempts, so a plain `tries` would exhaust during
 *     contention; a time bound is the right shape.
 *   - If the job is also {@see ShouldBeUnique},
 *     keep `uniqueFor` larger than the retryUntil window so the uniqueness lock
 *     outlives a long wait for the SSH slot.
 *
 * `expireAfter` auto-frees the lock if a holder dies mid-SSH, so one crashed
 * worker can't wedge a server's whole push lane.
 */
final class SerializeServerSsh
{
    public function __construct(
        public readonly string $serverId,
        public readonly int $releaseAfter = 8,
        public readonly int $expireAfter = 180,
    ) {}

    public function handle(object $job, callable $next): mixed
    {
        return (new WithoutOverlapping('ssh:server:'.$this->serverId))
            ->releaseAfter($this->releaseAfter)
            ->expireAfter($this->expireAfter)
            ->handle($job, $next);
    }
}
