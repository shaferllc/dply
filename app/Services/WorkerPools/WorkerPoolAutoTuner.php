<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Jobs\PushWorkerPoolHorizonConfigJob;
use App\Models\WorkerPool;
use App\Support\WorkerPools\WorkerPoolHorizonConfig;

/**
 * Derives a pool's Horizon process limits from what its boxes actually have.
 *
 * The autoscaler changes how MANY workers exist; this changes how hard each one
 * works. Defaults are deliberately conservative (a small pool, 128 MB workers)
 * because a fresh pool has no measurements — but once the stats probe has run we
 * know each member's vCPUs, memory and queue backlog, and can size the process
 * pool to the box instead of leaving a 4-vCPU worker running two processes.
 *
 * Opt-in per pool via `meta.auto_optimize.enabled`, and it only ever writes when
 * the derived values differ from the stored ones, so an operator who tunes the
 * knobs by hand is not fought on every scheduler tick.
 */
class WorkerPoolAutoTuner
{
    /**
     * dply's worker jobs are dominated by SSH round-trips and network waits
     * rather than CPU, so processes-per-core can exceed 1. Kept modest: each
     * process is a PHP interpreter holding its own memory.
     */
    private const PROCESSES_PER_VCPU = 3;

    /** Leave headroom for the OS, the agent, and any co-located services. */
    private const MEMORY_RESERVE_MB = 768;

    /**
     * @return array{changed: bool, reason: string, config: array<string, mixed>}
     */
    public function tune(WorkerPool $pool): array
    {
        $cfg = is_array($pool->meta['auto_optimize'] ?? null) ? $pool->meta['auto_optimize'] : [];
        if (($cfg['enabled'] ?? false) !== true) {
            return ['changed' => false, 'reason' => 'disabled', 'config' => []];
        }

        $members = $pool->servers()->get();
        if ($members->isEmpty()) {
            return ['changed' => false, 'reason' => 'no members', 'config' => []];
        }

        // Size to the SMALLEST member: one config is pushed to the whole pool, so
        // anything larger would over-subscribe the weakest box.
        $vcpus = null;
        $memoryMb = null;
        $backlog = 0;

        foreach ($members as $member) {
            $stats = is_array($member->meta['pool']['stats'] ?? null) ? $member->meta['pool']['stats'] : [];
            if ($stats === []) {
                continue;
            }

            $cpus = (int) ($stats['cpus'] ?? 0);
            if ($cpus > 0) {
                $vcpus = $vcpus === null ? $cpus : min($vcpus, $cpus);
            }

            $total = $this->totalMemoryMb((string) ($stats['mem'] ?? ''));
            if ($total > 0) {
                $memoryMb = $memoryMb === null ? $total : min($memoryMb, $total);
            }

            $backlog = max($backlog, (int) ($stats['queue_size'] ?? 0));
        }

        if ($vcpus === null || $memoryMb === null) {
            return ['changed' => false, 'reason' => 'no measurements yet', 'config' => []];
        }

        $current = WorkerPoolHorizonConfig::for($pool);
        $workerMemory = (int) $current['memory'];

        // Whichever binds first: cores or RAM.
        $byCpu = max(1, $vcpus * self::PROCESSES_PER_VCPU);
        $usable = max(0, $memoryMb - self::MEMORY_RESERVE_MB);
        $byMemory = $workerMemory > 0 ? max(1, intdiv($usable, $workerMemory)) : $byCpu;

        $max = max(1, min($byCpu, $byMemory));

        // Idle pools should not hold processes open; a backed-up pool should
        // start warm rather than ramping from one.
        $min = $backlog > 0 ? max(1, (int) ceil($max / 2)) : 1;

        if ((int) $current['min_processes'] === $min && (int) $current['max_processes'] === $max) {
            return ['changed' => false, 'reason' => 'already optimal', 'config' => $current];
        }

        // horizon_config is the USER config WorkerPoolHorizonConfig::for() reads.
        // meta['horizon'] is the collected snapshot (workload, recent jobs) and
        // must not be written here.
        $meta = is_array($pool->meta) ? $pool->meta : [];
        $horizon = is_array($meta['horizon_config'] ?? null) ? $meta['horizon_config'] : [];
        $horizon['min_processes'] = $min;
        $horizon['max_processes'] = $max;
        $meta['horizon_config'] = $horizon;

        $cfg['last_tuned_at'] = now()->toIso8601String();
        $cfg['last_reason'] = sprintf(
            '%d vCPU / %d MB → %d max (cpu %d, memory %d), backlog %d',
            $vcpus,
            $memoryMb,
            $max,
            $byCpu,
            $byMemory,
            $backlog,
        );
        $meta['auto_optimize'] = $cfg;

        $pool->forceFill(['meta' => $meta])->save();

        PushWorkerPoolHorizonConfigJob::dispatch((string) $pool->id);

        return ['changed' => true, 'reason' => $cfg['last_reason'], 'config' => $horizon];
    }

    /** Parse the probe's "used/total" memory reading into total MB. */
    private function totalMemoryMb(string $mem): int
    {
        if (! str_contains($mem, '/')) {
            return 0;
        }

        [, $total] = explode('/', $mem, 2);

        return (int) trim($total);
    }
}
