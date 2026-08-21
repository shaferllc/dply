<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueOperationMeteringTest;

use App\Models\Organization;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\QueueUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::factory()->create();

    $this->namespace = QueueNamespace::query()->create([
        'organization_id' => $org->id,
        'name' => 'orders',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);

    // Count what the store reports rather than what Redis holds: the point of
    // these tests is the accounting, and a Redis-less environment must not
    // decide whether they run.
    $this->meter = Mockery::mock(QueueUsageMeter::class)->makePartial();
    $this->recorded = 0;
    $this->meter->shouldReceive('recordOperations')
        ->andReturnUsing(function ($namespace, $ops) {
            $this->recorded += $ops;
        });
    $this->meter->shouldReceive('record')->andReturnNull();

    $this->app->instance(QueueUsageMeter::class, $this->meter);
});

function envelope(int $padding = 0): string
{
    return (string) json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\SendInvoice',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => str_repeat('x', $padding)],
    ]);
}

/** The canonical shape from the docs: dispatch, start, delete. */
test('a normal job costs three operations', function () {
    $store = app(QueueStore::class);

    $store->push($this->namespace, 'default', envelope());
    expect($this->recorded)->toBe(1);

    $claimed = $store->claim($this->namespace, 'default');
    expect($this->recorded)->toBe(2);

    $store->ack($this->namespace, $claimed[0]->id, $claimed[0]->reservationId);
    expect($this->recorded)->toBe(3);
});

test('a large payload costs its chunks on both dispatch and start', function () {
    $store = app(QueueStore::class);

    // Comfortably over two chunks once encoded.
    $store->push($this->namespace, 'default', envelope(140_000));
    $dispatch = $this->recorded;

    $store->claim($this->namespace, 'default');

    expect($dispatch)->toBeGreaterThan(2)
        ->and($this->recorded)->toBe($dispatch * 2);
});

test('a retry and a visibility extension each cost an operation', function () {
    $store = app(QueueStore::class);
    $store->push($this->namespace, 'default', envelope());
    $claimed = $store->claim($this->namespace, 'default');
    $before = $this->recorded;

    $store->heartbeat($this->namespace, $claimed[0]->id, $claimed[0]->reservationId);
    expect($this->recorded)->toBe($before + 1);

    $store->release($this->namespace, $claimed[0]->id, $claimed[0]->reservationId);
    expect($this->recorded)->toBe($before + 2);
});

test('a batch dispatch is counted per job, not per request', function () {
    app(QueueStore::class)->pushBulk($this->namespace, 'default', [envelope(), envelope(), envelope()]);

    expect($this->recorded)->toBe(3);
});

/** Metering sits in the push path; it must never be able to fail one. */
test('a metering outage does not fail the operation', function () {
    Redis::shouldReceive('incrby')->andThrow(new \RuntimeException('redis down'));
    Redis::shouldReceive('expire')->andReturn(true);
    Redis::shouldReceive('sadd')->andReturn(1);

    $meter = new QueueUsageMeter;

    $meter->recordOperations($this->namespace, 3);
})->throwsNoExceptions();
