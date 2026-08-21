<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\FleetWakerTest;

use App\Models\Organization;
use App\Modules\Queue\Contracts\WorkerRuntime;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\FleetWaker;
use App\Modules\Queue\Services\Runtimes\FakeWorkerRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'queue_service.fleets.runtime' => 'fake',
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
    ]);

    Cache::flush();

    $this->runtime = app(FakeWorkerRuntime::class);
    $this->app->instance(WorkerRuntime::class, $this->runtime);

    $org = Organization::factory()->create();

    $this->namespace = QueueNamespace::query()->create([
        'organization_id' => $org->id,
        'name' => 'orders',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);

    $this->fleet = ManagedQueueFleet::query()->create([
        'namespace_id' => $this->namespace->id,
        'organization_id' => $org->id,
        'queue' => 'default',
        'class' => ManagedQueueFleet::CLASS_FLEX,
        'status' => ManagedQueueFleet::STATUS_ACTIVE,
        'memory_mib' => 256,
        'min_workers' => 0,
        'max_workers' => 5,
        'meta' => ['image' => 'registry.dply.test/app:latest'],
    ]);
});

function waker(): FleetWaker
{
    return app(FleetWaker::class);
}

/** The difference between "scales to zero" and "is asleep". */
test('a push to a sleeping fleet starts a worker immediately', function () {
    expect(waker()->wake($this->namespace, 'default'))->toBeTrue()
        ->and($this->runtime->runningCount())->toBe(1);
});

/** A thousand-dispatch burst is a thousand pushes; only one may try to wake. */
test('wake attempts are throttled per fleet', function () {
    waker()->wake($this->namespace, 'default');
    $this->runtime->killSilently($this->runtime->log()[0]['ref']);

    // Even with the fleet asleep again, the window is still closed.
    expect(waker()->wake($this->namespace, 'default'))->toBeFalse()
        ->and($this->runtime->runningCount())->toBe(0);
});

test('a push to a different queue does not wake this fleet', function () {
    expect(waker()->wake($this->namespace, 'reports'))->toBeFalse()
        ->and($this->runtime->runningCount())->toBe(0);
});

test('a paused fleet is never woken by a push', function () {
    $this->fleet->forceFill(['status' => ManagedQueueFleet::STATUS_PAUSED])->save();

    expect(waker()->wake($this->namespace, 'default'))->toBeFalse()
        ->and($this->runtime->runningCount())->toBe(0);
});

/** A fleet with a floor already holds a worker; waking it is a wasted query. */
test('a fleet with a worker floor is not a wake candidate', function () {
    $this->fleet->forceFill(['min_workers' => 1])->save();

    expect(waker()->wake($this->namespace->fresh(), 'default'))->toBeFalse();
});

test('a namespace with no fleet is silently fine', function () {
    $this->fleet->delete();

    expect(waker()->wake($this->namespace, 'default'))->toBeFalse();
});

/** A queue that accepted a job is correct even if nothing woke. */
test('a wake failure never propagates to the caller', function () {
    $this->runtime->failNextStart();

    expect(waker()->wake($this->namespace, 'default'))->toBeFalse();
});
