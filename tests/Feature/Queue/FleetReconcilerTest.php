<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\FleetReconcilerTest;

use App\Models\Organization;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Contracts\WorkerRuntime;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\FleetReconciler;
use App\Modules\Queue\Services\Runtimes\FakeWorkerRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'queue_service.fleets.runtime' => 'fake',
        'queue_service.fleets.target_drain_seconds' => 20,
        // A worker authenticates over the public endpoint exactly as a
        // customer's app does, so without one there is nothing to point it at.
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
    ]);

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
        'memory_mib' => 512,
        'min_workers' => 0,
        'max_workers' => 5,
        'meta' => ['image' => 'registry.dply.test/app:latest', 'avg_job_seconds' => 1.0],
    ]);
});

function reconciler(): FleetReconciler
{
    return app(FleetReconciler::class);
}

function push(int $count, string $queue = 'default'): void
{
    $payloads = [];
    for ($i = 0; $i < $count; $i++) {
        $payloads[] = (string) json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => 'App\\Jobs\\SendInvoice',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => 'x'],
        ]);
    }

    app(QueueStore::class)->pushBulk(test()->namespace, $queue, $payloads);
}

test('a backlog starts workers and records them against the fleet', function () {
    push(60); // 60 x 1s = 60s of work / 20s target = 3 workers

    $decision = reconciler()->reconcile($this->fleet);

    expect($decision->desired)->toBe(3)
        ->and($this->runtime->runningCount())->toBe(3)
        ->and(ManagedQueueWorker::query()->live()->count())->toBe(3)
        ->and($this->fleet->fresh()->desired_workers)->toBe(3);
});

test('workers carry the fleet size they were started at', function () {
    push(20);
    reconciler()->reconcile($this->fleet);

    expect(ManagedQueueWorker::query()->first()->memory_mib)->toBe(512);
});

/** Draining to zero is the whole promise of the flex class. */
test('an empty queue winds the fleet down to zero after the quiet period', function () {
    push(60);
    reconciler()->reconcile($this->fleet);
    expect($this->runtime->runningCount())->toBe(3);

    // Drain the queue behind the reconciler's back, as workers would.
    app(QueueStore::class)->purge($this->namespace, 'default');

    reconciler()->reconcile($this->fleet->fresh()); // quiet tick 1 — holds
    expect($this->runtime->runningCount())->toBe(3);

    reconciler()->reconcile($this->fleet->fresh()); // quiet tick 2 — sleeps
    expect($this->runtime->runningCount())->toBe(0)
        ->and(ManagedQueueWorker::query()->live()->count())->toBe(0);
});

/** Billing is settled on teardown, not recomputed at invoice time. */
test('a stopped worker freezes its billed seconds', function () {
    push(20);
    reconciler()->reconcile($this->fleet);

    $worker = ManagedQueueWorker::query()->first();
    $worker->forceFill(['started_at' => now()->subSeconds(45)])->save();

    app(QueueStore::class)->purge($this->namespace, 'default');
    reconciler()->reconcile($this->fleet->fresh());
    reconciler()->reconcile($this->fleet->fresh());

    $settled = $worker->fresh();

    expect($settled->state)->toBe(ManagedQueueWorker::STATE_STOPPED)
        ->and($settled->stop_reason)->toBe('scale-down')
        ->and($settled->billed_seconds)->toBeGreaterThanOrEqual(45);
});

/** A container can die without telling anyone; the row must not stay "live". */
test('a worker that vanished is reaped before the fleet is sized', function () {
    push(60);
    reconciler()->reconcile($this->fleet);

    $victim = ManagedQueueWorker::query()->first();
    $this->runtime->killSilently($victim->runtime_ref);

    reconciler()->reconcile($this->fleet->fresh());

    expect($victim->fresh()->state)->toBe(ManagedQueueWorker::STATE_ERRORED)
        ->and($victim->fresh()->stop_reason)->toBe('vanished')
        // Replaced, not merely mourned: the backlog still needs three.
        ->and($this->runtime->runningCount())->toBe(3);
});

test('a refused placement settles the row instead of leaving a ghost', function () {
    push(20);
    $this->runtime->failNextStart();

    reconciler()->reconcile($this->fleet);

    expect(ManagedQueueWorker::query()->where('stop_reason', 'start-failed')->count())->toBe(1)
        ->and(ManagedQueueWorker::query()->live()->count())->toBe(0);
});

/** Without an image there is nothing to run; refuse rather than half-start. */
test('a fleet with no image starts nothing', function () {
    $this->fleet->forceFill(['meta' => ['avg_job_seconds' => 1.0]])->save();
    push(60);

    reconciler()->reconcile($this->fleet->fresh());

    expect($this->runtime->runningCount())->toBe(0)
        ->and(ManagedQueueWorker::query()->live()->count())->toBe(0);
});

test('wake starts exactly one worker on a sleeping fleet and is a no-op otherwise', function () {
    push(1);

    expect(reconciler()->wake($this->fleet))->toBeTrue()
        ->and($this->runtime->runningCount())->toBe(1);

    // Already awake: a second push must not stack another worker on.
    expect(reconciler()->wake($this->fleet->fresh()))->toBeFalse()
        ->and($this->runtime->runningCount())->toBe(1);
});

test('a paused fleet stops every worker and starts none', function () {
    push(60);
    reconciler()->reconcile($this->fleet);
    expect($this->runtime->runningCount())->toBe(3);

    $this->fleet->forceFill(['status' => ManagedQueueFleet::STATUS_PAUSED])->save();
    reconciler()->reconcile($this->fleet->fresh());

    expect($this->runtime->runningCount())->toBe(0);

    // Still paused, still a backlog: nothing comes back.
    reconciler()->reconcile($this->fleet->fresh());
    expect($this->runtime->runningCount())->toBe(0);
});

/** A worker with no endpoint would boot, fail every claim, and look like a broken queue. */
test('a fleet whose queue has no public endpoint starts nothing', function () {
    config(['queue_service.public_url' => '', 'dply.public_app_url' => '']);
    push(60);

    reconciler()->reconcile($this->fleet);

    expect($this->runtime->runningCount())->toBe(0)
        ->and(ManagedQueueWorker::query()->live()->count())->toBe(0);
});

test('workers are handed the namespace endpoint and a live credential', function () {
    push(20);
    reconciler()->reconcile($this->fleet);

    $env = app(\App\Modules\Queue\Services\FleetWorkerEnvironment::class)->for($this->fleet->fresh());

    expect($env['QUEUE_CONNECTION'])->toBe('dply')
        ->and($env['DPLY_QUEUE_URL'])->toBe('https://queue.dply.test/api/queue/v1/'.$this->namespace->id)
        ->and($env['DPLY_QUEUE_KEY'])->not->toBe('')
        ->and($env['DPLY_QUEUE_SECRET'])->not->toBe('');
});

/** One credential per namespace, not per container: revocation stays one row. */
test('scaling up does not mint a credential per worker', function () {
    push(60);
    reconciler()->reconcile($this->fleet);

    expect(\App\Modules\Queue\Models\QueueCredential::query()->count())->toBe(1);
});
