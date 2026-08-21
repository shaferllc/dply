<?php

declare(strict_types=1);

namespace App\Modules\Queue\Contracts;

use App\Modules\Queue\Support\WorkerHandle;
use App\Modules\Queue\Support\WorkerSpec;

/**
 * The substrate managed queue workers run on.
 *
 * Three operations, because that is all the autoscaler is allowed to know:
 * make one exist, make one stop, and tell me whether one is still alive.
 * Placement — which dply machine a container lands on, how images get there,
 * how memory is enforced — lives behind this line, so changing substrate is a
 * new implementation rather than a rewrite of the scaling logic.
 *
 * See docs/adr/managed-queue-workers.md, decision 3.
 */
interface WorkerRuntime
{
    /** Stable identifier persisted on the worker row. */
    public function name(): string;

    /**
     * Start one worker. Returns as soon as the container is accepted, not
     * when it is ready — the fleet reconciler tracks readiness separately,
     * because a runtime that blocked here would serialise a scale-up.
     *
     * @throws \RuntimeException when the worker could not be placed
     */
    public function start(WorkerSpec $spec): WorkerHandle;

    /**
     * Ask a worker to finish its current job and exit, waiting at most
     * `$graceSeconds` before killing it.
     *
     * Idempotent: stopping a worker that is already gone is a success, since
     * the common cause is a container that exited on its own.
     */
    public function stop(WorkerHandle $handle, int $graceSeconds): void;

    /**
     * Whether the runtime still has this worker.
     *
     * The reconciler uses this to notice workers that died without telling
     * anyone — the case that otherwise leaves a fleet believing it has
     * capacity it does not have, and a queue that quietly stops draining.
     */
    public function isAlive(WorkerHandle $handle): bool;
}
