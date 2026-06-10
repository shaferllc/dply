<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerMemberProviderProbe;
use App\Services\WorkerPools\WorkerPoolNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles SETTLED worker-pool replicas against their provider. The reconciler
 * (ReconcileWorkerPoolJob) only runs while a pool is actively converging, so a
 * member that is destroyed out-of-band AFTER the pool settles — its public IP
 * then recycled to a stranger's machine — would otherwise sit as a zombie: SSH
 * silently fails while the UI still shows it "active".
 *
 * This sweep asks the provider whether each active replica's instance still
 * exists and marks a CONFIRMED-gone box errored (degrading the pool + notifying)
 * so the operator can remove it and let the pool re-scale. Conservative by
 * construction: {@see WorkerMemberProviderProbe} only reports false on a
 * definitive "not found", so a flaky API never tears down a healthy member.
 */
class WorkerPoolMemberHealthCommand extends Command
{
    protected $signature = 'dply:worker-pools:member-health';

    protected $description = 'Mark settled worker-pool replicas errored when their provider instance no longer exists.';

    /** Don't re-probe the same member more often than this (provider API courtesy). */
    private const PROBE_THROTTLE_MINUTES = 10;

    public function handle(WorkerMemberProviderProbe $probe, WorkerPoolNotifier $notifier): int
    {
        $flagged = 0;

        WorkerPool::query()->with('servers')->each(function (WorkerPool $pool) use ($probe, $notifier, &$flagged): void {
            foreach ($pool->servers as $member) {
                if (! $this->isSettledReplica($member) || $this->probedRecently($member)) {
                    continue;
                }

                $this->stampProbedAt($member);

                $exists = $probe->instanceExists($member);
                if ($exists !== false) {
                    continue; // true (healthy) or null (couldn't tell) — never act
                }

                Log::warning('worker-pool: settled member instance not found at provider — marking errored', [
                    'pool_id' => $pool->id,
                    'member_id' => $member->id,
                    'provider' => $member->provider->value,
                    'provider_id' => $member->provider_id,
                ]);

                $this->markErrored($member);
                $pool->forceFill(['status' => WorkerPool::STATUS_DEGRADED])->save();
                $notifier->scaleFailed(
                    $pool->fresh() ?? $pool,
                    sprintf('Worker %s vanished at the provider and was marked errored.', $member->name),
                );
                $flagged++;
            }
        });

        $this->components->info("Flagged {$flagged} vanished worker-pool member(s).");

        return self::SUCCESS;
    }

    /** A non-primary member that has finished converging (active, or legacy null state). */
    private function isSettledReplica(Server $member): bool
    {
        if ($member->isPoolPrimary()) {
            return false;
        }

        return in_array($member->poolMemberState(), [WorkerPool::MEMBER_ACTIVE, null], true);
    }

    private function probedRecently(Server $member): bool
    {
        $at = $member->meta['pool']['existence_checked_at'] ?? null;

        return is_string($at)
            && Carbon::parse($at)->diffInMinutes(now(), absolute: true) < self::PROBE_THROTTLE_MINUTES;
    }

    private function stampProbedAt(Server $member): void
    {
        $meta = is_array($member->meta) ? $member->meta : [];
        $meta['pool'] = array_merge($meta['pool'] ?? [], ['existence_checked_at' => now()->toIso8601String()]);
        $member->forceFill(['meta' => $meta])->save();
    }

    private function markErrored(Server $member): void
    {
        $meta = is_array($member->meta) ? $member->meta : [];
        $meta['pool'] = array_merge($meta['pool'] ?? [], [
            'state' => WorkerPool::MEMBER_ERRORED,
            'state_since' => now()->toIso8601String(),
        ]);
        $member->forceFill(['meta' => $meta])->save();
    }
}
