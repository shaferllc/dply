<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

test('metrics tracks successful calls', function () {
    Cache::flush();
    $action = TestMetricsAction::make();

    $action->handle();
    $action->handle();

    $metrics = TestMetricsAction::getMetrics();

    expect($metrics['calls'])->toBe(2)
        ->and($metrics['successes'])->toBe(2)
        ->and($metrics['failures'])->toBe(0)
        ->and($metrics['success_rate'])->toBe(1.0)
        ->and($metrics['avg_duration_ms'])->toBeGreaterThan(0);
});

test('metrics tracks failures', function () {
    Cache::flush();
    $action = TestMetricsFailingAction::make();

    try {
        $action->handle();
    } catch (\RuntimeException $e) {
        // Expected
    }

    $metrics = TestMetricsFailingAction::getMetrics();

    expect($metrics['calls'])->toBe(1)
        ->and($metrics['successes'])->toBe(0)
        ->and($metrics['failures'])->toBe(1)
        ->and($metrics['success_rate'])->toBe(0.0);
});

test('metrics calculates success rate correctly', function () {
    Cache::flush();
    $action = TestMetricsAction::make();
    $failingAction = TestMetricsFailingAction::make();

    $action->handle();
    $action->handle();

    try {
        $failingAction->handle();
    } catch (\RuntimeException $e) {
        // Expected
    }

    $metrics = TestMetricsAction::getMetrics();

    expect($metrics['success_rate'])->toBe(1.0);

    $failingMetrics = TestMetricsFailingAction::getMetrics();
    expect($failingMetrics['success_rate'])->toBe(0.0);
});

test('metrics tracks duration', function () {
    Cache::flush();
    $action = TestMetricsAction::make();

    $action->handle();

    $metrics = TestMetricsAction::getMetrics();

    expect($metrics['avg_duration_ms'])->toBeGreaterThan(0)
        ->and($metrics['min_duration_ms'])->toBeGreaterThan(0)
        ->and($metrics['max_duration_ms'])->toBeGreaterThan(0);
});

test('metrics can be reset', function () {
    Cache::flush();
    $action = TestMetricsAction::make();

    $action->handle();
    $action->handle();

    TestMetricsAction::resetMetrics();

    $metrics = TestMetricsAction::getMetrics();

    expect($metrics['calls'])->toBe(0)
        ->and($metrics['successes'])->toBe(0);
});
