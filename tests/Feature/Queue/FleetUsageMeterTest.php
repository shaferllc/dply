<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\FleetUsageMeterTest;

use App\Models\Organization;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Models\QueueUsageDaily;
use App\Modules\Queue\Services\FleetUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create();

    $this->namespace = QueueNamespace::query()->create([
        'organization_id' => $this->org->id,
        'name' => 'orders',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
});

function fleet(string $class = ManagedQueueFleet::CLASS_FLEX): ManagedQueueFleet
{
    return ManagedQueueFleet::query()->create([
        'namespace_id' => test()->namespace->id,
        'organization_id' => test()->org->id,
        'queue' => 'q'.uniqid(),
        'class' => $class,
        'status' => ManagedQueueFleet::STATUS_ACTIVE,
        'memory_mib' => 512,
        'min_workers' => 0,
        'max_workers' => 5,
    ]);
}

function worker(ManagedQueueFleet $fleet, array $attributes = []): ManagedQueueWorker
{
    return ManagedQueueWorker::query()->create(array_merge([
        'fleet_id' => $fleet->id,
        'runtime' => 'fake',
        'state' => ManagedQueueWorker::STATE_STOPPED,
        'memory_mib' => 512,
        'started_at' => now()->subSeconds(60),
        'stopped_at' => now(),
    ], $attributes));
}

function usage(): ?QueueUsageDaily
{
    return QueueUsageDaily::query()->where('organization_id', test()->org->id)->first();
}

test('a stopped worker bills its memory for the time it ran', function () {
    worker(fleet());

    app(FleetUsageMeter::class)->roll();

    // 60s x 512 MiB.
    expect(usage()->flex_mib_seconds)->toBe(30_720)
        ->and(usage()->pro_mib_seconds)->toBe(0);
});

test('pro time is metered separately so its premium can be applied', function () {
    worker(fleet(ManagedQueueFleet::CLASS_PRO));

    app(FleetUsageMeter::class)->roll();

    expect(usage()->pro_mib_seconds)->toBe(30_720)
        ->and(usage()->flex_mib_seconds)->toBe(0);
});

/** The whole reason for the watermark: an always-on worker must bill before it dies. */
test('a still-running worker accrues without waiting to stop', function () {
    $running = worker(fleet(ManagedQueueFleet::CLASS_PRO), [
        'state' => ManagedQueueWorker::STATE_RUNNING,
        'started_at' => now()->subSeconds(120),
        'stopped_at' => null,
    ]);

    app(FleetUsageMeter::class)->roll();

    expect(usage()->pro_mib_seconds)->toBe(61_440)
        ->and($running->fresh()->billed_through_at)->not->toBeNull()
        // Still running, so it must stay meterable next hour.
        ->and($running->fresh()->billed_at)->toBeNull();
});

/** Two passes must not bill the same second twice. */
test('a second pass bills only the time since the last watermark', function () {
    $running = worker(fleet(), [
        'state' => ManagedQueueWorker::STATE_RUNNING,
        'started_at' => now()->subSeconds(60),
        'stopped_at' => null,
    ]);

    app(FleetUsageMeter::class)->roll();
    $first = usage()->flex_mib_seconds;

    app(FleetUsageMeter::class)->roll();

    // The second pass covers the sliver of wall-clock between the two calls,
    // not another full minute.
    expect(usage()->flex_mib_seconds)->toBeLessThan($first + 5_120)
        ->and(usage()->flex_mib_seconds)->toBeGreaterThanOrEqual($first);

    $running->forceFill(['state' => ManagedQueueWorker::STATE_STOPPED, 'stopped_at' => now()])->save();
    app(FleetUsageMeter::class)->roll();

    expect($running->fresh()->billed_at)->not->toBeNull();
});

test('a settled worker is never metered again', function () {
    $stopped = worker(fleet());

    app(FleetUsageMeter::class)->roll();
    $after = usage()->flex_mib_seconds;

    app(FleetUsageMeter::class)->roll();

    expect(usage()->flex_mib_seconds)->toBe($after)
        ->and($stopped->fresh()->billed_at)->not->toBeNull();
});

test('worker time accumulates onto the same daily row as other usage', function () {
    QueueUsageDaily::query()->create([
        'organization_id' => $this->org->id,
        'day' => now()->utc()->toDateString(),
        'source' => QueueUsageDaily::SOURCE_COUNTER,
        'jobs_pushed' => 42,
        'operations' => 126,
    ]);

    worker(fleet());
    app(FleetUsageMeter::class)->roll();

    expect(QueueUsageDaily::query()->count())->toBe(1)
        ->and(usage()->jobs_pushed)->toBe(42)
        ->and(usage()->operations)->toBe(126)
        ->and(usage()->flex_mib_seconds)->toBe(30_720);
});
