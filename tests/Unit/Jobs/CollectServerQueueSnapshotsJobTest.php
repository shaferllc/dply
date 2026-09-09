<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\CollectServerQueueSnapshotsJobTest;

use App\Jobs\CollectServerQueueSnapshotsJob;
use ReflectionMethod;

function call(string $method, mixed ...$args): mixed
{
    $job = new CollectServerQueueSnapshotsJob('01hzzzzzzzzzzzzzzzzzzzzzzz');
    $reflection = new ReflectionMethod($job, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($job, ...$args);
}

test('every fenced payload on the connection is extracted, not just the first', function () {
    // The whole point of one-SSH-per-server: several sites answer down the same
    // pipe, so a single-match parse would silently drop every site but one.
    $buffer = "noise\n"
        .'DPLY_Q_START{"site_id":"a","failed_total":2,"queues":[{"queue":"default","pending":5}]}DPLY_Q_END'."\n"
        ."more noise\n"
        .'DPLY_Q_START{"site_id":"b","failed_total":0,"queues":[{"queue":"emails","pending":0}]}DPLY_Q_END'."\n";

    $payloads = call('extract', $buffer);

    expect($payloads)->toHaveCount(2)
        ->and($payloads[0]['site_id'])->toBe('a')
        ->and($payloads[1]['site_id'])->toBe('b');
});

test('a site that printed nothing usable cannot break the others', function () {
    // cd fails, PHP fatals, tinker prints a warning — the surviving sites still
    // have to land.
    $buffer = "PHP Warning: something\n"
        .'DPLY_Q_START{"site_id":"a","queues":[]}DPLY_Q_END'."\n"
        .'DPLY_Q_STARTnot json at allDPLY_Q_END'."\n";

    expect(call('extract', $buffer))->toHaveCount(1)
        ->and(call('extract', ''))->toBe([])
        ->and(call('extract', 'no markers here'))->toBe([]);
});

test('non-numeric and negative depths degrade to null or zero rather than poisoning history', function () {
    expect(call('int', 12))->toBe(12)
        ->and(call('int', '7'))->toBe(7)
        // A driver that cannot answer returns null; a chart plots a gap, which
        // is honest. Storing 0 would read as "the queue drained".
        ->and(call('int', null))->toBeNull()
        ->and(call('int', 'unknown'))->toBeNull()
        ->and(call('int', -3))->toBe(0);
});
