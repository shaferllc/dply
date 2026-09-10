<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueGroupFifoTest;

use App\Models\Organization;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function fifoNamespace(): QueueNamespace
{
    return QueueNamespace::query()->create([
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'orders',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
}

function fifoStore(): QueueStore
{
    return app(QueueStore::class);
}

function fifoEnvelope(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\SyncCustomer',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'timeout' => 60,
        'data' => ['commandName' => 'App\\Jobs\\SyncCustomer', 'command' => 'O:1:"a":0:{}'],
    ], $overrides));
}

/** @return list<string|null> */
function storedGroupKeys(QueueNamespace $namespace): array
{
    // The store writes on its own connection; reading through the default one
    // finds nothing.
    return DB::connection('dply_queue')->table('dply_queue_jobs')
        ->where('namespace_id', $namespace->id)
        ->orderBy('id')
        ->pluck('group_key')
        ->all();
}

test('an explicit group key overrides one carried in the payload', function () {
    $namespace = fifoNamespace();

    fifoStore()->push($namespace, 'default', fifoEnvelope(['groupKey' => 'from-payload']), 0, 'from-caller');

    expect(storedGroupKeys($namespace))->toBe(['from-caller']);
});

test('the payload key still applies when the caller passes none', function () {
    $namespace = fifoNamespace();

    fifoStore()->push($namespace, 'default', fifoEnvelope(['groupKey' => 'customer:42']));

    expect(storedGroupKeys($namespace))->toBe(['customer:42']);
});

test('a blank group key leaves the job ungrouped', function () {
    // A client that always sends MessageGroupId and sometimes leaves it empty
    // must not collapse its whole queue onto one serialised group.
    $namespace = fifoNamespace();

    fifoStore()->push($namespace, 'default', fifoEnvelope(), 0, '   ');

    expect(storedGroupKeys($namespace))->toBe([null]);
});

test('a group key is capped at the column width', function () {
    $namespace = fifoNamespace();

    fifoStore()->push($namespace, 'default', fifoEnvelope(), 0, str_repeat('k', 200));

    expect(storedGroupKeys($namespace)[0])->toHaveLength(128);
});

test('pushBulk keeps one group key per entry rather than collapsing the batch', function () {
    // The trap the shared DelaySeconds sets: folding group keys the same way
    // would serialise unrelated work and order none of it.
    $namespace = fifoNamespace();

    fifoStore()->pushBulk(
        $namespace,
        'default',
        [fifoEnvelope(), fifoEnvelope(), fifoEnvelope()],
        0,
        ['customer:1', null, 'customer:2'],
    );

    expect(storedGroupKeys($namespace))->toBe(['customer:1', null, 'customer:2']);
});

test('only one job per group is ever in flight', function () {
    $namespace = fifoNamespace();
    $store = fifoStore();

    $first = $store->push($namespace, 'default', fifoEnvelope(), 0, 'customer:1');
    $store->push($namespace, 'default', fifoEnvelope(), 0, 'customer:1');

    $claimed = $store->claim($namespace, 'default', 10);

    expect($claimed)->toHaveCount(1)
        ->and($claimed[0]->id)->toBe($first);

    // Still nothing more to hand out while the first is leased.
    expect($store->claim($namespace, 'default', 10))->toBe([]);
});

test('one claim never returns two jobs from the same group', function () {
    // The half that is easy to miss: the in-flight NOT EXISTS is evaluated
    // before any row in the batch is reserved, so without the row_number()
    // partition a single claim of limit N returns N jobs of one group and
    // orders nothing.
    $namespace = fifoNamespace();
    $store = fifoStore();

    foreach (range(1, 5) as $ignored) {
        $store->push($namespace, 'default', fifoEnvelope(), 0, 'customer:1');
    }

    expect($store->claim($namespace, 'default', 5))->toHaveCount(1);
});

test('different groups and ungrouped jobs still run in parallel', function () {
    $namespace = fifoNamespace();
    $store = fifoStore();

    $store->push($namespace, 'default', fifoEnvelope(), 0, 'customer:1');
    $store->push($namespace, 'default', fifoEnvelope(), 0, 'customer:2');
    $store->push($namespace, 'default', fifoEnvelope());
    $store->push($namespace, 'default', fifoEnvelope());

    // One per group, plus every ungrouped job: grouping one customer must not
    // cost the rest of the queue any concurrency.
    expect($store->claim($namespace, 'default', 10))->toHaveCount(4);
});

test('the next job in a group becomes claimable once the first is acked', function () {
    $namespace = fifoNamespace();
    $store = fifoStore();

    $first = $store->push($namespace, 'default', fifoEnvelope(), 0, 'customer:1');
    $second = $store->push($namespace, 'default', fifoEnvelope(), 0, 'customer:1');

    $claimed = $store->claim($namespace, 'default', 10);
    $store->ack($namespace, $claimed[0]->id, $claimed[0]->reservationId);

    $next = $store->claim($namespace, 'default', 10);

    expect($claimed[0]->id)->toBe($first)
        ->and($next)->toHaveCount(1)
        ->and($next[0]->id)->toBe($second);
});
