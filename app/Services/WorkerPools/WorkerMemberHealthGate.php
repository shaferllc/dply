<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Jobs\ControlWorkerDaemonJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * Pauses a worker that cannot do the work, and resumes it when it can again.
 *
 * A replica consumes the SAME Redis queues as everything else, so a member that
 * boots but cannot reach its database (missing PDO driver, blocked network,
 * wrong credentials) does not sit idle — it reserves jobs, fails them, and moves
 * on to the next. That poisons the shared queue, and worst of all it swallows
 * the remediation jobs sent to repair it: the fix is dispatched, the broken
 * worker claims it, and the fix fails. The box cannot heal itself.
 *
 * `horizon:pause` stops the member reserving new jobs while leaving it running
 * and reachable, so diagnostics and fixes still work and nothing needs
 * rebuilding. The gate reverses itself the moment the member reports healthy.
 */
class WorkerMemberHealthGate
{
    /**
     * Reasons a member must not be consuming. Keyed by the probe stat that
     * proves it, so the recorded reason says what was actually observed.
     */
    public function unhealthyReason(Server $member): ?string
    {
        $stats = is_array($member->meta['pool']['stats'] ?? null) ? $member->meta['pool']['stats'] : [];
        if ($stats === []) {
            return null;
        }

        // No measurement is not evidence of failure — never pause on silence.
        if (($stats['redis_ping'] ?? '') === '') {
            return null;
        }

        if (($stats['redis_ping'] ?? '') !== 'PONG') {
            return 'cannot reach Redis ('.($stats['redis_ping'] ?: 'unknown').')';
        }

        return null;
    }

    /**
     * Apply the gate to one member. Returns the action taken, or null.
     */
    public function apply(Server $member, ?Site $site = null): ?string
    {
        $site ??= Site::query()->where('server_id', $member->id)->first();
        if (! $site instanceof Site) {
            return null;
        }

        $reason = $this->unhealthyReason($member);
        $meta = is_array($member->meta) ? $member->meta : [];
        $gate = is_array($meta['pool']['health_gate'] ?? null) ? $meta['pool']['health_gate'] : [];
        $paused = ($gate['paused'] ?? false) === true;

        if ($reason !== null && ! $paused) {
            ControlWorkerDaemonJob::dispatch((string) $site->id, 'horizon:pause');

            $this->record($member, $meta, [
                'paused' => true,
                'reason' => $reason,
                'paused_at' => now()->toIso8601String(),
            ]);

            Log::warning('worker-pool: member paused, unhealthy', [
                'server_id' => (string) $member->id,
                'reason' => $reason,
            ]);

            return 'paused';
        }

        // Only ever resume what THIS gate paused — an operator who paused a
        // worker deliberately should not be overridden by a health check.
        if ($reason === null && $paused) {
            ControlWorkerDaemonJob::dispatch((string) $site->id, 'horizon:continue');

            $this->record($member, $meta, [
                'paused' => false,
                'reason' => null,
                'resumed_at' => now()->toIso8601String(),
            ]);

            Log::info('worker-pool: member resumed, healthy again', [
                'server_id' => (string) $member->id,
            ]);

            return 'resumed';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $gate
     */
    private function record(Server $member, array $meta, array $gate): void
    {
        $pool = is_array($meta['pool'] ?? null) ? $meta['pool'] : [];
        $pool['health_gate'] = array_merge(
            is_array($pool['health_gate'] ?? null) ? $pool['health_gate'] : [],
            $gate,
        );
        $meta['pool'] = $pool;

        $member->forceFill(['meta' => $meta])->save();
    }
}
