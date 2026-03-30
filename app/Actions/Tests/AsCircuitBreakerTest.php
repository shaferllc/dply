<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

test('circuit breaker allows successful calls', function () {
    Cache::flush();
    $action = TestCircuitBreakerAction::make();

    $result = $action->handle();

    expect($result)->toBe('success');
});

test('circuit breaker opens after threshold failures', function () {
    Cache::flush();
    $action = TestCircuitBreakerFailingAction::make();

    // Trigger failures up to threshold
    for ($i = 0; $i < 2; $i++) {
        try {
            $action->handle();
        } catch (\RuntimeException $e) {
            // Expected
        }
    }

    // Next call should open circuit
    expect(fn () => $action->handle())
        ->toThrow(\RuntimeException::class, 'Circuit breaker is open');
});

test('circuit breaker closes after timeout', function () {
    Cache::flush();
    $action = TestCircuitBreakerFailingAction::make();
    $action->timeoutSeconds = 1; // Short timeout for testing

    // Open the circuit
    for ($i = 0; $i < 2; $i++) {
        try {
            $action->handle();
        } catch (\RuntimeException $e) {
            // Expected
        }
    }

    // Wait for timeout
    sleep(2);

    // Circuit should be half-open, allow one more attempt
    // This will fail, but circuit should allow the attempt
    try {
        $action->handle();
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->not->toContain('Circuit breaker is open');
    }
});

test('circuit breaker resets on success', function () {
    Cache::flush();
    $action = TestCircuitBreakerAction::make();

    // Simulate some failures
    $failingAction = TestCircuitBreakerFailingAction::make();
    for ($i = 0; $i < 2; $i++) {
        try {
            $failingAction->handle();
        } catch (\RuntimeException $e) {
            // Expected
        }
    }

    // Success should reset the circuit
    $result = $action->handle();
    expect($result)->toBe('success');
});
