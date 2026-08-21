<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ActivationLogLinesTest;

use App\Modules\Serverless\Support\ActivationLogLines;

/**
 * OpenWhisk closes every activation by writing a sentinel to both streams. The
 * platform strips the sentinel text but still emits the timestamped line, so a
 * function invoked once a minute by the scheduler accumulates two empty lines
 * per minute — enough to bury real output and to make a function that has
 * never logged anything look like it logs constantly.
 */
test('it drops the empty stream lines that close every activation', function () {
    $lines = ActivationLogLines::meaningful([
        '2026-08-21T02:05:16.805709862Z stderr:',
        '2026-08-21T02:05:16.805948427Z stdout:',
    ]);

    expect($lines)->toBe([]);
});

test('it keeps real output on both streams', function () {
    $lines = ActivationLogLines::meaningful([
        '2026-08-21T02:05:16.805709862Z stderr:',
        '2026-08-21T02:05:16.805948427Z stdout: Cache cleared successfully.',
        '2026-08-21T02:06:10.280826026Z stderr: PHP Warning: something',
        '2026-08-21T02:06:10.281005699Z stdout:',
    ]);

    expect($lines)->toBe([
        '2026-08-21T02:05:16.805948427Z stdout: Cache cleared successfully.',
        '2026-08-21T02:06:10.280826026Z stderr: PHP Warning: something',
    ]);
});

test('it drops a sentinel that reaches us unstripped', function () {
    expect(ActivationLogLines::meaningful([
        '2026-08-21T02:05:16.805709862Z stdout: XXX_THE_END_OF_A_WHISK_ACTIVATION_XXX',
    ]))->toBe([]);
});

/**
 * A runtime that frames its output differently should surface as-is rather
 * than disappear — only positively-identified empty lines are dropped.
 */
test('it keeps lines that do not match the openwhisk framing', function () {
    $lines = ActivationLogLines::meaningful([
        'plain unprefixed output',
        '2026-08-21T02:05:16Z info: some other framing',
    ]);

    expect($lines)->toHaveCount(2);
});

test('it ignores non-strings and blank lines', function () {
    expect(ActivationLogLines::meaningful(['', '   ', null, 42, ['x']]))->toBe([]);
});

test('whitespace after the stream prefix still counts as empty', function () {
    expect(ActivationLogLines::meaningful([
        '2026-08-21T02:05:16.805709862Z stdout:    ',
    ]))->toBe([]);
});
