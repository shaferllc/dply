<?php

namespace Tests\Unit\Queue\FleetAutoscalerTest;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Services\FleetAutoscaler;
use App\Modules\Queue\Support\FleetSignal;

function fleet(array $attributes = []): ManagedQueueFleet
{
    $fleet = new ManagedQueueFleet(array_merge([
        'queue' => 'default',
        'class' => ManagedQueueFleet::CLASS_FLEX,
        'status' => ManagedQueueFleet::STATUS_ACTIVE,
        'memory_mib' => 256,
        'min_workers' => 0,
        'max_workers' => 10,
    ], $attributes));

    $fleet->quiet_ticks = $attributes['quiet_ticks'] ?? 0;

    return $fleet;
}

function signal(int $pending = 0, int $reserved = 0, int $live = 0, float $avg = 0.5): FleetSignal
{
    return new FleetSignal($pending, $reserved, $live, $avg);
}

beforeEach(function () {
    config(['queue_service.fleets.target_drain_seconds' => 20]);
    $this->scaler = new FleetAutoscaler;
});

test('an idle flex fleet sleeps at zero once the quiet period has passed', function () {
    $decision = $this->scaler->decide(fleet(['quiet_ticks' => 2]), signal(live: 1));

    expect($decision->desired)->toBe(0)
        ->and($decision->reason)->toContain('sleeping at zero');
});

/** Scale-down is damped: the gap between two jobs must not cost a teardown. */
test('an idle fleet holds its workers for the first quiet ticks', function () {
    $decision = $this->scaler->decide(fleet(['quiet_ticks' => 0]), signal(live: 2));

    expect($decision->desired)->toBe(2)
        ->and($decision->quietTicks)->toBe(1)
        ->and($decision->reason)->toContain('holding');
});

test('a pro fleet never sleeps below one worker', function () {
    $decision = $this->scaler->decide(
        fleet(['class' => ManagedQueueFleet::CLASS_PRO, 'min_workers' => 0, 'quiet_ticks' => 2]),
        signal(live: 3),
    );

    expect($decision->desired)->toBe(1);
});

/** The backlog is work, not a job count: 1,000 trivial jobs is not 1,000 workers. */
test('backlog is sized by duration, not by job count', function () {
    // 1000 jobs x 0.01s = 10s of work, well inside the 20s drain target.
    expect($this->scaler->decide(fleet(), signal(pending: 1000, avg: 0.01))->desired)->toBe(1);

    // 1000 jobs x 1s = 1000s of work; 1000/20 = 50, capped at max_workers.
    expect($this->scaler->decide(fleet(['max_workers' => 10]), signal(pending: 1000, avg: 1.0))->desired)->toBe(10);
});

test('the maximum is a hard cap and says so', function () {
    $decision = $this->scaler->decide(fleet(['max_workers' => 4]), signal(pending: 5000, avg: 1.0));

    expect($decision->desired)->toBe(4)
        ->and($decision->reason)->toContain('capped at the maximum');
});

/** Sizing below the in-flight count means stopping a worker mid-job. */
test('never sizes below the number of jobs already in flight', function () {
    $decision = $this->scaler->decide(fleet(), signal(pending: 0, reserved: 3, live: 3));

    expect($decision->desired)->toBe(3)
        ->and($decision->reason)->toContain('in flight');
});

/** The one state that must never persist: work waiting, nothing draining. */
test('any pending work guarantees at least one worker', function () {
    $decision = $this->scaler->decide(fleet(), signal(pending: 1, avg: 0.001));

    expect($decision->desired)->toBe(1);
});

test('a paused fleet winds down to zero regardless of backlog', function () {
    $decision = $this->scaler->decide(
        fleet(['status' => ManagedQueueFleet::STATUS_PAUSED]),
        signal(pending: 500, reserved: 2, live: 4),
    );

    expect($decision->desired)->toBe(0)
        ->and($decision->reason)->toContain('paused');
});

test('a minimum floor is held even with an empty queue', function () {
    $decision = $this->scaler->decide(fleet(['min_workers' => 2, 'quiet_ticks' => 2]), signal(live: 2));

    expect($decision->desired)->toBe(2)
        ->and($decision->reason)->toContain('floor');
});

/** An unmeasured queue must not look free to drain. */
test('falls back to a default job duration when nothing has been measured', function () {
    // 400 jobs x 0.5s default = 200s of work / 20s target = 10 workers.
    $decision = $this->scaler->decide(fleet(['max_workers' => 20]), signal(pending: 400, avg: 0.0));

    expect($decision->desired)->toBe(10);
});

test('wake applies only to an active fleet with no workers', function () {
    expect($this->scaler->shouldWake(fleet(), 0))->toBeTrue()
        ->and($this->scaler->shouldWake(fleet(), 1))->toBeFalse()
        ->and($this->scaler->shouldWake(fleet(['status' => ManagedQueueFleet::STATUS_PAUSED]), 0))->toBeFalse()
        ->and($this->scaler->shouldWake(fleet(['max_workers' => 0]), 0))->toBeFalse();
});
