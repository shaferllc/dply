<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueJobDurationsTest;

use App\Models\Organization;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\QueueJobDurations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::factory()->create();

    $this->namespace = QueueNamespace::query()->create([
        'organization_id' => $org->id,
        'name' => 'orders',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);

    $this->durations = app(QueueJobDurations::class);
});

function envelope(): string
{
    return (string) json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\SendInvoice',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => 'x'],
    ]);
}

/** The store sees the claim and the ack, so it can time the gap itself. */
test('acking a job records how long it ran', function () {
    $store = app(QueueStore::class);
    $store->push($this->namespace, 'default', envelope());

    $claimed = $store->claim($this->namespace, 'default');
    $job = $claimed[0];

    // Backdate the reservation: the job "ran" for three seconds.
    DB::connection(config('queue_service.store.connection', 'dply_queue'))
        ->table('dply_queue_jobs')
        ->where('id', $job->id)
        ->update(['reserved_at' => now()->subSeconds(3)]);

    $store->ack($this->namespace, $job->id, $job->reservationId);

    expect($this->durations->average($this->namespace, 'default'))->toBeGreaterThanOrEqual(2.5)
        ->and($this->durations->samples($this->namespace, 'default'))->toBe(1);
});

test('a bulk ack records every job in the batch', function () {
    $store = app(QueueStore::class);
    $store->pushBulk($this->namespace, 'default', [envelope(), envelope(), envelope()]);

    $claimed = $store->claim($this->namespace, 'default', 3);

    $store->ackBulk($this->namespace, array_map(
        fn ($job) => [$job->id, $job->reservationId],
        $claimed,
    ));

    expect($this->durations->samples($this->namespace, 'default'))->toBe(3);
});

/** One slow job must not move the fleet on its own. */
test('the average is weighted toward recent samples without lurching', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->durations->record($this->namespace, 'default', 1.0);
    }
    expect($this->durations->average($this->namespace, 'default'))->toBe(1.0);

    $this->durations->record($this->namespace, 'default', 11.0);

    // 0.2 * 11 + 0.8 * 1 = 3.0 — moved, but not to 11.
    expect($this->durations->average($this->namespace, 'default'))->toBe(3.0);
});

/** A redelivered job "ran" for hours; counting it would size for phantom work. */
test('implausible durations are ignored', function () {
    $this->durations->record($this->namespace, 'default', 2.0);
    $this->durations->record($this->namespace, 'default', 7200.0);
    $this->durations->record($this->namespace, 'default', -5.0);

    expect($this->durations->average($this->namespace, 'default'))->toBe(2.0)
        ->and($this->durations->samples($this->namespace, 'default'))->toBe(1);
});

test('an unmeasured queue reports nothing rather than zero', function () {
    expect($this->durations->average($this->namespace, 'never-used'))->toBeNull();
});
