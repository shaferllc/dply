<?php

namespace Tests\Unit\Queue\QueueOperationsTest;

use App\Modules\Queue\Support\QueueOperations;

test('a small payload is one operation', function () {
    expect(QueueOperations::forPayload(str_repeat('x', 1_000)))->toBe(1);
});

/** A megabyte costs the store sixteen times what a small job does. */
test('a payload is charged in 64 KiB chunks', function () {
    expect(QueueOperations::forPayload(str_repeat('x', 65_536)))->toBe(1)
        ->and(QueueOperations::forPayload(str_repeat('x', 65_537)))->toBe(2)
        ->and(QueueOperations::forPayload(str_repeat('x', 1_048_576)))->toBe(16);
});

/** Rounding an empty body to nothing would make an empty dispatch free. */
test('an empty payload still costs one operation', function () {
    expect(QueueOperations::forPayload(''))->toBe(1);
});

test('a batch is the sum of its parts, not one operation', function () {
    expect(QueueOperations::forPayloads([
        str_repeat('x', 10),
        str_repeat('x', 70_000),
        str_repeat('x', 10),
    ]))->toBe(4);
});
