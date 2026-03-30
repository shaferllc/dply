<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use Tests\TestCase;

uses(TestCase::class);

test('retry succeeds after multiple attempts', function () {
    $action = TestRetryAction::make();

    $result = $action->handle();

    expect($result)->toBe('success')
        ->and($action->attempts)->toBe(3);
});

test('retry fails after max retries exceeded', function () {
    $action = TestRetryAction::make();
    $action->maxRetries = 2;

    expect(fn () => $action->handle())
        ->toThrow(\RuntimeException::class, 'Temporary failure')
        ->and($action->attempts)->toBe(3); // 1 initial + 2 retries
});

test('retry uses custom delay', function () {
    $action = TestRetryWithCustomDelayAction::make();

    $start = microtime(true);
    $result = $action->handle();
    $duration = microtime(true) - $start;

    expect($result)->toBe('success')
        ->and($action->attempts)->toBe(2)
        ->and($duration)->toBeGreaterThan(0.1); // At least 100ms delay
});

test('retry respects shouldRetry condition', function () {
    $action = TestRetryWithConditionAction::make();

    expect(fn () => $action->handle())
        ->toThrow(\Error::class, 'Fatal error')
        ->and($action->attempts)->toBe(1); // No retries for Error
});
