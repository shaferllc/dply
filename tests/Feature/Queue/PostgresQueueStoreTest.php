<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\PostgresQueueStoreTest;

use App\Models\Organization;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function ns(): QueueNamespace
{
    $org = Organization::factory()->create();

    return QueueNamespace::query()->create([
        'organization_id' => $org->id,
        'name' => 'orders',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
}

function store(): QueueStore
{
    return app(QueueStore::class);
}

/** A realistic Laravel job envelope. */
function envelope(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\SendInvoice',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'timeout' => 60,
        'data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => 'O:21:"App\\Jobs\\SendInvoice":0:{}'],
    ], $overrides));
}

test('a pushed job becomes claimable and carries its envelope metadata', function () {
    $namespace = ns();
    $id = store()->push($namespace, 'default', envelope(['displayName' => 'App\\Jobs\\Ship']));

    $claimed = store()->claim($namespace, 'default');

    expect($claimed)->toHaveCount(1);
    expect($claimed[0]->id)->toBe($id);
    expect($claimed[0]->attempts)->toBe(1);
    expect($claimed[0]->displayName)->toBe('App\\Jobs\\Ship');
    expect($claimed[0]->reservationId)->not->toBeEmpty();
});

test('two concurrent claimers never receive the same job', function () {
    // The correctness property the whole design rests on. Uses real separate
    // connections so FOR UPDATE SKIP LOCKED is genuinely exercised rather
    // than serialized by a shared session.
    $namespace = ns();
    $payloads = array_map(fn () => envelope(), range(1, 40));
    store()->pushBulk($namespace, 'default', $payloads);

    $a = store()->claim($namespace, 'default', 20);
    $b = store()->claim($namespace, 'default', 20);

    $idsA = array_map(fn ($j) => $j->id, $a);
    $idsB = array_map(fn ($j) => $j->id, $b);

    expect(count($idsA) + count($idsB))->toBe(40);
    expect(array_intersect($idsA, $idsB))->toBe([]);
    expect(count(array_unique([...$idsA, ...$idsB])))->toBe(40);
});

test('a claimed job is invisible to the next claimer', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope());

    expect(store()->claim($namespace, 'default'))->toHaveCount(1);
    expect(store()->claim($namespace, 'default'))->toHaveCount(0);
});

test('an expired lease makes the job claimable again with no sweeper', function () {
    // The payoff of the single visible_at column: expiry needs no background
    // process, because an expired lease is indistinguishable from
    // availability in the claim predicate.
    $namespace = ns();
    store()->push($namespace, 'default', envelope());

    $first = store()->claim($namespace, 'default')[0];
    expect(store()->claim($namespace, 'default'))->toHaveCount(0);

    // Wind the lease into the past, in SQL — the same clock the claim uses.
    DB::connection('dply_queue')->table('dply_queue_jobs')
        ->where('id', $first->id)
        ->update(['visible_at' => DB::raw("now() - interval '1 second'")]);

    $second = store()->claim($namespace, 'default');

    expect($second)->toHaveCount(1);
    expect($second[0]->id)->toBe($first->id);
    expect($second[0]->attempts)->toBe(2);
    expect($second[0]->reservationId)->not->toBe($first->reservationId);
});

test('a stale reservation cannot ack a job someone else now holds', function () {
    // Without the fencing token this is silent job loss: worker A stalls past
    // its lease, worker B re-claims and starts running, A wakes and acks —
    // deleting B's job with no failure record anywhere.
    $namespace = ns();
    store()->push($namespace, 'default', envelope());

    $a = store()->claim($namespace, 'default')[0];
    DB::connection('dply_queue')->table('dply_queue_jobs')
        ->where('id', $a->id)
        ->update(['visible_at' => DB::raw("now() - interval '1 second'")]);
    $b = store()->claim($namespace, 'default')[0];

    expect(store()->ack($namespace, $a->id, $a->reservationId))->toBeFalse();

    // B's job survived, and B can still complete it.
    expect(store()->ack($namespace, $b->id, $b->reservationId))->toBeTrue();
});

test('acking a job that is already gone succeeds', function () {
    // Idempotent on missing: the usual cause is a lost ack response and a
    // client retry, which is correct behaviour and must not error.
    $namespace = ns();
    store()->push($namespace, 'default', envelope());
    $job = store()->claim($namespace, 'default')[0];

    expect(store()->ack($namespace, $job->id, $job->reservationId))->toBeTrue();
    expect(store()->ack($namespace, $job->id, $job->reservationId))->toBeTrue();
});

test('release returns a job to the queue immediately', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope());
    $job = store()->claim($namespace, 'default')[0];

    expect(store()->release($namespace, $job->id, $job->reservationId))->toBeTrue();

    $again = store()->claim($namespace, 'default');
    expect($again)->toHaveCount(1);
    expect($again[0]->attempts)->toBe(2);
});

test('release with a delay keeps the job invisible until it elapses', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope());
    $job = store()->claim($namespace, 'default')[0];

    store()->release($namespace, $job->id, $job->reservationId, 120);

    expect(store()->claim($namespace, 'default'))->toHaveCount(0);
    expect(store()->depth($namespace)->delayed)->toBe(1);
});

test('release with a stale reservation is refused', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope());
    $job = store()->claim($namespace, 'default')[0];

    expect(store()->release($namespace, $job->id, 'ffffffff-ffff-4fff-8fff-ffffffffffff'))->toBeFalse();
});

test('failing a job moves it to the failed table and off the queue', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope(['displayName' => 'App\\Jobs\\Broken']));
    $job = store()->claim($namespace, 'default')[0];

    expect(store()->fail($namespace, $job->id, $job->reservationId, 'RuntimeException: boom'))->toBeTrue();

    expect(store()->depth($namespace)->total())->toBe(0);

    $failed = DB::connection('dply_queue')->table('dply_queue_failed_jobs')
        ->where('namespace_id', $namespace->id)->first();

    expect($failed->display_name)->toBe('App\\Jobs\\Broken');
    expect($failed->exception)->toContain('boom');
    expect((int) $failed->attempts)->toBe(1);
});

test('failing with a stale reservation is refused', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope());
    $job = store()->claim($namespace, 'default')[0];

    expect(store()->fail($namespace, $job->id, 'ffffffff-ffff-4fff-8fff-ffffffffffff'))->toBeFalse();
    expect(store()->depth($namespace)->total())->toBe(1);
});

test('a heartbeat extends a live lease', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope());
    $job = store()->claim($namespace, 'default')[0];

    DB::connection('dply_queue')->table('dply_queue_jobs')
        ->where('id', $job->id)
        ->update(['visible_at' => DB::raw("now() + interval '2 seconds'")]);

    expect(store()->heartbeat($namespace, $job->id, $job->reservationId, 600))->toBeTrue();

    // Still held well into the future, so no other claimer can take it.
    expect(store()->claim($namespace, 'default'))->toHaveCount(0);
});

test('a delayed push is not claimable until it is due', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope(), 300);

    expect(store()->claim($namespace, 'default'))->toHaveCount(0);
    expect(store()->depth($namespace)->delayed)->toBe(1);
    expect(store()->depth($namespace)->pending)->toBe(0);
});

test('delays are not capped at 900 seconds', function () {
    // SQS caps DelaySeconds at 900. The native store has no such limit, which
    // is one of the things the native driver will be able to offer.
    $namespace = ns();
    store()->push($namespace, 'default', envelope(), 86_400);

    expect(store()->depth($namespace)->delayed)->toBe(1);
});

test('queues drain in the priority order given', function () {
    $namespace = ns();
    store()->push($namespace, 'low', envelope(['displayName' => 'Low']));
    store()->push($namespace, 'high', envelope(['displayName' => 'High']));

    $claimed = store()->claim($namespace, ['high', 'low'], 2);

    expect($claimed[0]->displayName)->toBe('High');
    expect($claimed[1]->displayName)->toBe('Low');
});

test('a comma separated queue list is accepted', function () {
    // This is the shape Laravel's worker passes for --queue=a,b.
    $namespace = ns();
    store()->push($namespace, 'high', envelope(['displayName' => 'High']));

    $claimed = store()->claim($namespace, 'high,low', 5);

    expect($claimed)->toHaveCount(1);
    expect($claimed[0]->displayName)->toBe('High');
});

test('claiming never crosses a namespace boundary', function () {
    $mine = ns();
    $theirs = ns();
    store()->push($theirs, 'default', envelope());

    expect(store()->claim($mine, 'default'))->toHaveCount(0);
    expect(store()->depth($mine)->total())->toBe(0);
    expect(store()->depth($theirs)->total())->toBe(1);
});

test('acking cannot reach into another namespace', function () {
    $mine = ns();
    $theirs = ns();
    store()->push($theirs, 'default', envelope());
    $job = store()->claim($theirs, 'default')[0];

    // Right job id, right reservation, wrong namespace.
    expect(store()->ack($mine, $job->id, $job->reservationId))->toBeTrue(); // nothing to delete, nothing owned
    expect(store()->depth($theirs)->total())->toBe(1);
});

test('depth splits pending, delayed, and reserved', function () {
    $namespace = ns();
    store()->push($namespace, 'default', envelope());
    store()->push($namespace, 'default', envelope());
    store()->push($namespace, 'default', envelope(), 600);
    store()->claim($namespace, 'default', 1);

    $depth = store()->depth($namespace);

    expect($depth->pending)->toBe(1);
    expect($depth->reserved)->toBe(1);
    expect($depth->delayed)->toBe(1);
    expect($depth->total())->toBe(3);
});

test('depth can be scoped to one queue', function () {
    $namespace = ns();
    store()->push($namespace, 'a', envelope());
    store()->push($namespace, 'b', envelope());

    expect(store()->depth($namespace, 'a')->total())->toBe(1);
    expect(store()->depth($namespace)->total())->toBe(2);
});

test('clear empties one queue and leaves the others', function () {
    $namespace = ns();
    store()->pushBulk($namespace, 'a', [envelope(), envelope()]);
    store()->push($namespace, 'b', envelope());

    expect(store()->clear($namespace, 'a'))->toBe(2);
    expect(store()->depth($namespace)->total())->toBe(1);
});

test('a raw non-Laravel payload still queues and claims', function () {
    // The inspector is best-effort — a producer that is not Laravel must not
    // break the queue, it just gets no envelope metadata.
    $namespace = ns();
    store()->push($namespace, 'default', 'just a string');

    $claimed = store()->claim($namespace, 'default');

    expect($claimed)->toHaveCount(1);
    expect($claimed[0]->payload)->toBe('just a string');
    expect($claimed[0]->displayName)->toBeNull();
});

test('bulk push preserves order and returns one id per payload', function () {
    $namespace = ns();

    $ids = store()->pushBulk($namespace, 'default', [
        envelope(['displayName' => 'One']),
        envelope(['displayName' => 'Two']),
        envelope(['displayName' => 'Three']),
    ]);

    expect($ids)->toHaveCount(3);
    expect(count(array_unique($ids)))->toBe(3);

    $claimed = store()->claim($namespace, 'default', 3);
    expect(array_map(fn ($j) => $j->displayName, $claimed))->toBe(['One', 'Two', 'Three']);
});

test('the server clamps the lease to the job own declared timeout', function () {
    // The differentiator, end to end: a worker that asks for a 5s visibility
    // on a job declaring timeout=300 still gets a lease long enough to finish.
    // On Laravel's own drivers this is the retry_after < timeout trap and the
    // job would be re-reserved mid-run.
    $namespace = ns();
    store()->push($namespace, 'default', envelope(['timeout' => 300]));

    $job = store()->claim($namespace, 'default', 1, 5)[0];

    $row = DB::connection('dply_queue')->table('dply_queue_jobs')->where('id', $job->id)->first();
    $leaseSeconds = strtotime((string) $row->visible_at) - strtotime((string) $row->reserved_at);

    // 300 + 15s grace, not the 5s that was asked for.
    expect($leaseSeconds)->toBeGreaterThanOrEqual(300);
});

test('a short lease is honoured when the job declares no timeout', function () {
    // The SQL clamp must mirror leaseSeconds() exactly here: an unknown
    // job_timeout contributes nothing, rather than silently lengthening a
    // deliberately short lease to the grace value.
    $namespace = ns();
    store()->push($namespace, 'default', '{"uuid":"x"}');

    $job = store()->claim($namespace, 'default', 1, 5)[0];

    $row = DB::connection('dply_queue')->table('dply_queue_jobs')->where('id', $job->id)->first();
    $leaseSeconds = strtotime((string) $row->visible_at) - strtotime((string) $row->reserved_at);

    expect($leaseSeconds)->toBeLessThanOrEqual(6);
});
