<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Contracts\WorkerRuntime;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Support\FleetSignal;
use App\Modules\Queue\Support\ScalingDecision;
use App\Modules\Queue\Support\WorkerHandle;
use App\Modules\Queue\Support\WorkerSpec;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes a fleet's real worker count match what {@see FleetAutoscaler} asked
 * for, and keeps the worker rows honest while doing it.
 *
 * Order matters here and is not arbitrary: reap first, then decide, then act.
 * Deciding before reaping would size the fleet against workers that are
 * already dead — the failure that leaves a queue with "four workers" and no
 * drain at all.
 */
class FleetReconciler
{
    public function __construct(
        private readonly QueueStore $store,
        private readonly WorkerRuntime $runtime,
        private readonly FleetAutoscaler $autoscaler,
        private readonly FleetWorkerEnvironment $environment,
        private readonly QueueJobDurations $durations,
    ) {}

    /** Reap dead workers, size the fleet, apply the difference. */
    public function reconcile(ManagedQueueFleet $fleet): ScalingDecision
    {
        $this->reap($fleet);

        $decision = $this->autoscaler->decide($fleet, $this->signal($fleet));

        // The reason is persisted, not just logged: "why is this fleet running
        // four workers" is the first question anyone asks of an autoscaler,
        // and the panel has nowhere else to read the answer from.
        $meta = is_array($fleet->meta) ? $fleet->meta : [];
        $meta['last_reason'] = $decision->reason;

        $fleet->forceFill([
            'desired_workers' => $decision->desired,
            'quiet_ticks' => $decision->quietTicks,
            'meta' => $meta,
        ]);

        if ($decision->isChange()) {
            $fleet->forceFill(['last_scaled_at' => now()]);
        }

        $fleet->save();

        $delta = $decision->delta();

        if ($delta > 0) {
            $this->scaleUp($fleet, $delta);
        } elseif ($delta < 0) {
            $this->scaleDown($fleet, -$delta);
        }

        return $decision;
    }

    /**
     * Start one worker because a push arrived at a sleeping fleet.
     *
     * Separate from reconcile() so the push path stays a single INSERT and one
     * runtime call: routing a wake through a full reconcile would put a queue
     * depth query on the hot path of every dispatch.
     */
    public function wake(ManagedQueueFleet $fleet): bool
    {
        $live = $this->liveWorkers($fleet)->count();

        if (! $this->autoscaler->shouldWake($fleet, $live)) {
            return false;
        }

        return $this->scaleUp($fleet, 1) > 0;
    }

    /**
     * Mark workers the runtime no longer has.
     *
     * A container can die without telling anyone — OOM, host reboot, a
     * platform reclaiming spare capacity. Until the row is closed it counts
     * as live, so the fleet believes it has drain capacity it does not have.
     */
    private function reap(ManagedQueueFleet $fleet): void
    {
        foreach ($this->liveWorkers($fleet)->get() as $worker) {
            if ($worker->runtime_ref === null) {
                continue;
            }

            if ($this->runtime->isAlive($this->handle($worker))) {
                $worker->forceFill(['last_seen_at' => now()])->save();

                continue;
            }

            $this->settle($worker, ManagedQueueWorker::STATE_ERRORED, 'vanished');
        }
    }

    private function signal(ManagedQueueFleet $fleet): FleetSignal
    {
        $depth = $this->store->depth($fleet->namespace, $fleet->queue);

        // Measured from real claim-to-ack times where the queue has run jobs.
        // The mirror onto the fleet is what makes a cache flush cost accuracy
        // instead of correctness: the estimate degrades to the last known
        // value, then to the autoscaler's own default, never to zero — which
        // would make every backlog look free to drain.
        $measured = $this->durations->average($fleet->namespace, $fleet->queue);
        $remembered = (float) ($fleet->meta['avg_job_seconds'] ?? 0.0);

        if ($measured !== null && $measured !== $remembered) {
            $meta = is_array($fleet->meta) ? $fleet->meta : [];
            $meta['avg_job_seconds'] = $measured;
            $meta['avg_job_samples'] = $this->durations->samples($fleet->namespace, $fleet->queue);
            $fleet->forceFill(['meta' => $meta]);
        }

        return new FleetSignal(
            pending: $depth->pending,
            reserved: $depth->reserved,
            liveWorkers: $this->liveWorkers($fleet)->count(),
            avgJobSeconds: $measured ?? $remembered,
        );
    }

    /** @return int workers actually started */
    private function scaleUp(ManagedQueueFleet $fleet, int $count): int
    {
        $image = trim((string) ($fleet->meta['image'] ?? ''));

        if ($image === '') {
            Log::warning('queue.fleet.no_image', ['fleet_id' => $fleet->id]);

            return 0;
        }

        try {
            // Resolved once per scale-up, not per worker: every worker on a
            // fleet shares the namespace's credential, and minting inside the
            // loop would create one per container on first use.
            $env = $this->environment->for($fleet);
        } catch (Throwable $e) {
            Log::warning('queue.fleet.env_failed', [
                'fleet_id' => $fleet->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $started = 0;

        for ($i = 0; $i < $count; $i++) {
            $worker = ManagedQueueWorker::create([
                'fleet_id' => $fleet->id,
                'runtime' => $this->runtime->name(),
                'state' => ManagedQueueWorker::STATE_STARTING,
                'memory_mib' => $fleet->memory_mib,
                'started_at' => now(),
            ]);

            try {
                $handle = $this->runtime->start(WorkerSpec::forFleet($fleet, $image, $env));
            } catch (Throwable $e) {
                // The row is settled rather than deleted: dply asked a
                // substrate for capacity and was refused, and that is the
                // event worth being able to count later.
                $this->settle($worker, ManagedQueueWorker::STATE_ERRORED, 'start-failed');

                Log::warning('queue.fleet.start_failed', [
                    'fleet_id' => $fleet->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $worker->forceFill([
                'runtime_ref' => $handle->ref,
                'host_server_id' => $handle->hostServerId,
                'state' => ManagedQueueWorker::STATE_RUNNING,
                'ready_at' => now(),
                'last_seen_at' => now(),
            ])->save();

            $started++;
        }

        return $started;
    }

    /**
     * Stop the newest workers first.
     *
     * They are the ones added for the burst that just ended, so they are the
     * least likely to be mid-way through long work — and the oldest worker is
     * the one whose image layers and opcache are warm.
     */
    private function scaleDown(ManagedQueueFleet $fleet, int $count): void
    {
        $doomed = $this->liveWorkers($fleet)
            ->orderByDesc('started_at')
            ->limit($count)
            ->get();

        $grace = $fleet->graceSeconds();

        foreach ($doomed as $worker) {
            $worker->forceFill(['state' => ManagedQueueWorker::STATE_DRAINING])->save();

            try {
                if ($worker->runtime_ref !== null) {
                    $this->runtime->stop($this->handle($worker), $grace);
                }
            } catch (Throwable $e) {
                Log::warning('queue.fleet.stop_failed', [
                    'worker_id' => $worker->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->settle($worker, ManagedQueueWorker::STATE_STOPPED, 'scale-down');
        }
    }

    /**
     * Close a worker row and freeze what it owes.
     *
     * billed_seconds is written once, here. Leaving it to be recomputed at
     * invoice time would make the number depend on when the invoice ran.
     */
    private function settle(ManagedQueueWorker $worker, string $state, string $reason): void
    {
        $stoppedAt = now();

        $worker->forceFill([
            'state' => $state,
            'stop_reason' => $reason,
            'stopped_at' => $stoppedAt,
            'billed_seconds' => $worker->started_at === null
                ? 0
                : (int) $worker->started_at->diffInSeconds($stoppedAt, absolute: true),
        ])->save();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ManagedQueueWorker> */
    private function liveWorkers(ManagedQueueFleet $fleet)
    {
        return ManagedQueueWorker::query()->where('fleet_id', $fleet->id)->live();
    }

    private function handle(ManagedQueueWorker $worker): WorkerHandle
    {
        return new WorkerHandle((string) $worker->runtime_ref, $worker->runtime, $worker->host_server_id);
    }
}
