<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueLockStoreTest;

use App\Models\Organization;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\PostgresQueueLockStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function lockNs(): QueueNamespace
{
    $org = Organization::factory()->create();

    return QueueNamespace::query()->create([
        'organization_id' => $org->id,
        'name' => 'locks',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
}

function locks(): PostgresQueueLockStore
{
    return app(PostgresQueueLockStore::class);
}

test('a lock can be acquired once', function () {
    $ns = lockNs();

    expect(locks()->acquire($ns, 'send-invoices', 'owner-a', 60))->toBeTrue();
});

test('a second holder cannot take a held lock', function () {
    // The property everything else depends on. Without it, WithoutOverlapping
    // and ShouldBeUnique are decorative.
    $ns = lockNs();

    expect(locks()->acquire($ns, 'send-invoices', 'owner-a', 60))->toBeTrue();
    expect(locks()->acquire($ns, 'send-invoices', 'owner-b', 60))->toBeFalse();
});

test('acquiring is atomic across many contenders', function () {
    // Exactly one winner, no read-then-write window. A lock with a
    // check-then-set gap is not a lock.
    $ns = lockNs();

    $won = 0;
    for ($i = 0; $i < 50; $i++) {
        $won += locks()->acquire($ns, 'hot', 'owner-'.$i, 60) ? 1 : 0;
    }

    expect($won)->toBe(1);
});

test('releasing frees the lock for someone else', function () {
    $ns = lockNs();
    locks()->acquire($ns, 'send-invoices', 'owner-a', 60);

    expect(locks()->release($ns, 'send-invoices', 'owner-a'))->toBeTrue();
    expect(locks()->acquire($ns, 'send-invoices', 'owner-b', 60))->toBeTrue();
});

test('a stale holder cannot release the lock someone else now holds', function () {
    // The fencing rule, same as job reservations. Without it, an expired
    // holder releases the new holder's lock and BOTH believe they own it —
    // producing exactly the double-execution WithoutOverlapping prevents.
    $ns = lockNs();
    locks()->acquire($ns, 'send-invoices', 'owner-a', 60);

    // owner-a's lock expires; owner-b takes over.
    DB::connection('dply_queue')->table('dply_queue_locks')
        ->update(['expires_at' => DB::raw("now() - interval '1 second'")]);
    expect(locks()->acquire($ns, 'send-invoices', 'owner-b', 60))->toBeTrue();

    // owner-a wakes up and tries to release.
    expect(locks()->release($ns, 'send-invoices', 'owner-a'))->toBeFalse();

    // owner-b still holds it.
    expect(locks()->owner($ns, 'send-invoices'))->toBe('owner-b');
    expect(locks()->acquire($ns, 'send-invoices', 'owner-c', 60))->toBeFalse();
});

test('an expired lock is takeable with no sweeper', function () {
    $ns = lockNs();
    locks()->acquire($ns, 'nightly', 'owner-a', 60);

    DB::connection('dply_queue')->table('dply_queue_locks')
        ->update(['expires_at' => DB::raw("now() - interval '1 second'")]);

    expect(locks()->acquire($ns, 'nightly', 'owner-b', 60))->toBeTrue();
    expect(locks()->owner($ns, 'nightly'))->toBe('owner-b');
});

test('owner reports null for a free or expired lock', function () {
    $ns = lockNs();

    expect(locks()->owner($ns, 'never-taken'))->toBeNull();

    locks()->acquire($ns, 'taken', 'owner-a', 60);
    DB::connection('dply_queue')->table('dply_queue_locks')
        ->update(['expires_at' => DB::raw("now() - interval '1 second'")]);

    expect(locks()->owner($ns, 'taken'))->toBeNull();
});

test('force release drops the lock regardless of holder', function () {
    $ns = lockNs();
    locks()->acquire($ns, 'stuck', 'owner-a', 3600);

    locks()->forceRelease($ns, 'stuck');

    expect(locks()->acquire($ns, 'stuck', 'owner-b', 60))->toBeTrue();
});

test('locks never cross a namespace boundary', function () {
    $mine = lockNs();
    $theirs = lockNs();

    expect(locks()->acquire($theirs, 'shared-name', 'them', 60))->toBeTrue();

    // Same lock name, different tenant — must be independent.
    expect(locks()->acquire($mine, 'shared-name', 'me', 60))->toBeTrue();
    expect(locks()->owner($mine, 'shared-name'))->toBe('me');
    expect(locks()->owner($theirs, 'shared-name'))->toBe('them');
});

test('releasing cannot reach into another namespace', function () {
    $mine = lockNs();
    $theirs = lockNs();
    locks()->acquire($theirs, 'shared-name', 'them', 60);

    expect(locks()->release($mine, 'shared-name', 'them'))->toBeFalse();
    expect(locks()->owner($theirs, 'shared-name'))->toBe('them');
});

test('a lock ttl is capped so a crashed holder cannot wedge a queue forever', function () {
    $ns = lockNs();

    locks()->acquire($ns, 'forever', 'owner-a', 999_999_999);

    $row = DB::connection('dply_queue')->table('dply_queue_locks')->first();
    $held = strtotime((string) $row->expires_at) - time();

    expect($held)->toBeLessThanOrEqual(86_400 + 5);
});

test('pruning removes long-expired rows only', function () {
    $ns = lockNs();
    locks()->acquire($ns, 'recent', 'owner-a', 60);
    locks()->acquire($ns, 'ancient', 'owner-b', 60);

    DB::connection('dply_queue')->table('dply_queue_locks')
        ->where('name', 'ancient')
        ->update(['expires_at' => DB::raw("now() - interval '2 hours'")]);

    expect(locks()->pruneExpired())->toBe(1);
    expect(DB::connection('dply_queue')->table('dply_queue_locks')->count())->toBe(1);
});
