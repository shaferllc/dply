<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Models\Organization;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Models\QueueUsageDaily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Meters dply Queue usage: jobs pushed, per org, per UTC day
 * (docs/adr/dply-queue.md, decision 9).
 *
 * Why a counter rather than the Logs pattern of metering the store after the
 * fact: an acked job row is **deleted**. By the time a nightly pass ran, the
 * evidence of a job that was pushed and completed would be gone, and the
 * customer would be billed only for their backlog — which is precisely the
 * usage that costs us least. So the count has to be taken at push.
 *
 * The counter holds a **running total for the day**, and the flush writes that
 * absolute value rather than a delta. That is what makes a flush racing a push
 * harmless: the worst case is the row briefly trailing the counter until the
 * next flush, never a double-count and never a lost one.
 *
 * Metering runs whether or not billing is on, matching dply Logs — the numbers
 * have to exist before anyone can calibrate a price against them.
 */
class QueueUsageMeter
{
    /** Counters outlive the billing period they belong to, then expire. */
    private const COUNTER_TTL_SECONDS = 60 * 60 * 24 * 45;

    /** The set of `org:day` pairs with a live counter, so the flush can find them. */
    private const INDEX_KEY = 'dplyq:usage:index';

    /**
     * Record jobs pushed against a namespace's organization.
     *
     * Never throws. This sits in the push path, and a metering outage must
     * not become a queue outage — dply losing a count is a dply problem, and
     * rejecting the customer's job over it would be the wrong trade every
     * time. A failure is logged and the push proceeds.
     */
    public function record(QueueNamespace $namespace, int $jobs): void
    {
        $organizationId = (string) $namespace->organization_id;

        if ($jobs <= 0 || $organizationId === '') {
            return;
        }

        try {
            $day = now()->utc()->toDateString();
            $member = $organizationId.':'.$day;

            Redis::incrby($this->counterKey($organizationId, $day), $jobs);
            Redis::expire($this->counterKey($organizationId, $day), self::COUNTER_TTL_SECONDS);
            Redis::sadd(self::INDEX_KEY, $member);
        } catch (Throwable $e) {
            Log::warning('queue.usage_record_failed', [
                'organization_id' => $organizationId,
                'jobs' => $jobs,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record billable queue operations against a namespace's organization.
     *
     * Same counter mechanism, same never-throws contract, and for the same
     * reason as {@see record()}: an acked job row is deleted, so the evidence
     * has to be counted as it happens or not at all. What differs is that
     * operations accrue on every step of a job's life, not just the push, so
     * this is called from the claim and ack paths too.
     */
    public function recordOperations(QueueNamespace $namespace, int $operations): void
    {
        $organizationId = (string) $namespace->organization_id;

        if ($operations <= 0 || $organizationId === '') {
            return;
        }

        try {
            $day = now()->utc()->toDateString();

            Redis::incrby($this->operationsKey($organizationId, $day), $operations);
            Redis::expire($this->operationsKey($organizationId, $day), self::COUNTER_TTL_SECONDS);
            Redis::sadd(self::INDEX_KEY, $organizationId.':'.$day);
        } catch (Throwable $e) {
            Log::warning('queue.operations_record_failed', [
                'organization_id' => $organizationId,
                'operations' => $operations,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Flush live counters into {@see QueueUsageDaily}.
     *
     * Idempotent — each row is written as the counter's absolute total, keyed
     * on org + day + source, so re-running changes nothing.
     *
     * Counters for days older than the retention window are dropped from the
     * index after one final flush, so the index does not grow without bound.
     *
     * @return array{reachable: bool, orgs: int, jobs: int, days: int, skipped: int}
     */
    public function flush(bool $dryRun = false): array
    {
        $result = ['reachable' => true, 'orgs' => 0, 'jobs' => 0, 'days' => 0, 'skipped' => 0];

        try {
            $members = Redis::smembers(self::INDEX_KEY);
        } catch (Throwable $e) {
            // No Redis in this environment (dev, CI) — nothing to flush, and
            // failing the scheduler over it would be noise.
            Log::info('queue.usage_flush_unreachable', ['error' => $e->getMessage()]);
            $result['reachable'] = false;

            return $result;
        }

        if (! is_array($members) || $members === []) {
            return $result;
        }

        // Only meter orgs that still exist: a row for a deleted one would
        // violate the FK, and the cascade has already dropped its history.
        $pairs = $this->parseMembers($members);
        $knownOrgIds = Organization::query()
            ->whereIn('id', array_keys($pairs))
            ->pluck('id')
            ->all();
        $known = array_flip($knownOrgIds);

        $orgsSeen = [];

        foreach ($pairs as $organizationId => $days) {
            foreach ($days as $day => $member) {
                $total = $this->readCounter($organizationId, $day);

                if (! isset($known[$organizationId]) || $total === null) {
                    $result['skipped']++;
                    $this->forgetMember($member, $dryRun);

                    continue;
                }

                $result['days']++;
                $result['jobs'] += $total;
                $orgsSeen[$organizationId] = true;

                if ($dryRun) {
                    continue;
                }

                $attributes = [
                    'jobs_pushed' => $total,
                    'meta' => ['metered_via' => 'QueueUsageMeter'],
                ];

                // Only written when the counter is still there. Coalescing a
                // missing counter to zero would let an expired key erase a
                // total that was already flushed correctly.
                $operations = $this->readOperations($organizationId, $day);

                if ($operations !== null) {
                    $attributes['operations'] = $operations;
                }

                QueueUsageDaily::query()->updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'day' => $day,
                        'source' => QueueUsageDaily::SOURCE_COUNTER,
                    ],
                    $attributes,
                );

                // The row is now authoritative for a day whose counter is
                // about to expire; drop the index entry so the set tracks
                // only live days.
                if ($this->isStale($day)) {
                    $this->forgetMember($member, $dryRun);
                }
            }
        }

        $result['orgs'] = count($orgsSeen);

        return $result;
    }

    /** Jobs pushed by an org so far in the current UTC month. */
    public function monthToDateJobs(Organization $organization): int
    {
        $now = now()->utc();

        return QueueUsageDaily::totalFor($organization->id, $now->copy()->startOfMonth(), $now);
    }

    private function counterKey(string $organizationId, string $day): string
    {
        return 'dplyq:usage:'.$organizationId.':'.$day;
    }

    private function operationsKey(string $organizationId, string $day): string
    {
        return 'dplyq:ops:'.$organizationId.':'.$day;
    }

    /**
     * Operations counted for a day, or null when the counter is gone.
     *
     * A missing counter must not zero a row that already holds a flushed
     * total — the caller coalesces to 0 only for rows being written fresh,
     * and an absolute-total write means a late flush cannot double-count.
     */
    private function readOperations(string $organizationId, string $day): ?int
    {
        try {
            $value = Redis::get($this->operationsKey($organizationId, $day));

            return $value === null ? null : (int) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /** Null when the counter has expired out from under its index entry. */
    private function readCounter(string $organizationId, string $day): ?int
    {
        try {
            $value = Redis::get($this->counterKey($organizationId, $day));
        } catch (Throwable $e) {
            return null;
        }

        return $value === null || $value === false ? null : (int) $value;
    }

    private function forgetMember(string $member, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        try {
            Redis::srem(self::INDEX_KEY, $member);
        } catch (Throwable $e) {
            // Leaving a member behind costs one wasted read next flush.
        }
    }

    /**
     * A day is stale once it is far enough past that no further push can land
     * on it — one day of grace covers clock skew and a late in-flight push.
     */
    private function isStale(string $day): bool
    {
        return Carbon::parse($day)->lt(now()->utc()->startOfDay()->subDay());
    }

    /**
     * Group index members into `[orgId => [day => member]]`, dropping anything
     * malformed rather than letting it break the whole flush.
     *
     * @param  array<int, mixed>  $members
     * @return array<string, array<string, string>>
     */
    private function parseMembers(array $members): array
    {
        $pairs = [];

        foreach ($members as $member) {
            $member = (string) $member;
            $split = strrpos($member, ':');

            if ($split === false || $split === 0) {
                continue;
            }

            $organizationId = substr($member, 0, $split);
            $day = substr($member, $split + 1);

            if ($organizationId === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
                continue;
            }

            $pairs[$organizationId][$day] = $member;
        }

        return $pairs;
    }
}
