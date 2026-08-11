<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueBatchAckTest;

use App\Models\Organization;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueAction;
use App\Modules\Queue\Support\QueueRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function batchNs(string $name = 'orders'): QueueNamespace
{
    $org = Organization::factory()->create();

    return QueueNamespace::query()->create([
        'organization_id' => $org->id,
        'name' => $name,
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
}

function batchStore(): QueueStore
{
    return app(QueueStore::class);
}

function batchEnvelope(): string
{
    return (string) json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\SendInvoice',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'timeout' => 60,
    ]);
}

it('deletes a whole batch in one go', function () {
    $namespace = batchNs();
    $store = batchStore();

    foreach (range(1, 5) as $ignored) {
        $store->push($namespace, 'default', batchEnvelope());
    }

    $claimed = $store->claim($namespace, 'default', 5);
    expect($claimed)->toHaveCount(5);

    $results = $store->ackBulk($namespace, array_map(
        fn ($job): array => [$job->id, $job->reservationId],
        $claimed,
    ));

    expect($results)->toHaveCount(5);
    expect(array_values($results))->each->toBeTrue();
    expect($store->depth($namespace)->total())->toBe(0);
});

it('matches the id and reservation as a pair, not as two sets', function () {
    $namespace = batchNs();
    $store = batchStore();

    $store->push($namespace, 'default', batchEnvelope());
    $store->push($namespace, 'default', batchEnvelope());

    [$a, $b] = $store->claim($namespace, 'default', 2);

    // A stale handle for job A carrying job B's live reservation. Matching ids
    // and reservations as two independent IN lists would delete both rows;
    // matching the tuple deletes neither.
    $results = $store->ackBulk($namespace, [[$a->id, $b->reservationId]]);

    expect($results[$a->id])->toBeFalse();
    expect($store->depth($namespace)->total())->toBe(2);
});

it('treats an already-deleted job as a success', function () {
    $namespace = batchNs();
    $store = batchStore();

    $store->push($namespace, 'default', batchEnvelope());
    [$job] = $store->claim($namespace, 'default', 1);

    $store->ack($namespace, $job->id, $job->reservationId);

    // The usual cause is a lost response and a client retry — reporting failure
    // would make a correct client think it lost work.
    expect($store->ackBulk($namespace, [[$job->id, $job->reservationId]]))
        ->toBe([$job->id => true]);
});

it('reports failure when the job is held under a newer reservation', function () {
    $namespace = batchNs();
    $store = batchStore();

    $store->push($namespace, 'default', batchEnvelope());
    [$first] = $store->claim($namespace, 'default', 1);

    // Expire the lease and let someone else claim it.
    DB::connection('dply_queue')->table('dply_queue_jobs')
        ->where('id', $first->id)
        ->update(['visible_at' => now()->subMinute()]);

    [$second] = $store->claim($namespace, 'default', 1);
    expect($second->reservationId)->not->toBe($first->reservationId);

    // The first worker's late ack must not destroy the second worker's job.
    expect($store->ackBulk($namespace, [[$first->id, $first->reservationId]]))
        ->toBe([$first->id => false]);
    expect($store->depth($namespace)->total())->toBe(1);
});

it('cannot delete across a namespace boundary', function () {
    $mine = batchNs('mine');
    $theirs = batchNs('theirs');
    $store = batchStore();

    $store->push($theirs, 'default', batchEnvelope());
    [$job] = $store->claim($theirs, 'default', 1);

    // Correct id AND correct reservation, wrong tenant.
    expect($store->ackBulk($mine, [[$job->id, $job->reservationId]]))
        ->toBe([$job->id => true]);   // not visible to this tenant, so "gone"
    expect($store->depth($theirs)->total())->toBe(1);
});

it('does nothing on an empty batch', function () {
    expect(batchStore()->ackBulk(batchNs(), []))->toBe([]);
});

it('gives polling reads their own allowance', function () {
    config()->set('queue_service.rate.poll_multiplier', 4);

    $namespace = batchNs();
    $context = new QueueRequestContext(
        namespace: $namespace,
        credential: new QueueCredential,
        requestsPerMinute: 600,
    );

    // An empty receive changes nothing and costs one indexed query; charging it
    // against the write budget lets idle workers starve the drain.
    expect($context->pollsPerMinute())->toBe(2400);
});

it('classifies actions the same way the controller dispatches them', function (string $target, bool $isPoll) {
    $request = Request::create('/api/queue/v1/default', 'POST', server: ['HTTP_X_AMZ_TARGET' => $target]);

    expect(QueueAction::isPoll($request))->toBe($isPoll);
})->with([
    'receive is a poll' => ['AmazonSQS.ReceiveMessage', true],
    'send is not' => ['AmazonSQS.SendMessage', false],
    'delete is not' => ['AmazonSQS.DeleteMessage', false],
    'batch delete is not' => ['AmazonSQS.DeleteMessageBatch', false],
]);

it('falls back to the strict bucket when the action cannot be read', function () {
    // No X-Amz-Target and no parseable body: an unknown action must not be
    // handed the generous polling allowance.
    $request = Request::create('/api/queue/v1/default', 'POST', content: 'not json');

    expect(QueueAction::isPoll($request))->toBeFalse();
});
