<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DplyRuntime;

test('all mode runs scheduler and expects horizon and reverb', function () {
    config([
        'dply_runtime.mode' => 'all',
        'dply_runtime.worker_role' => 'primary',
    ]);

    expect(DplyRuntime::mode())->toBe('all')
        ->and(DplyRuntime::runsScheduler())->toBeTrue()
        ->and(DplyRuntime::expectsHorizon())->toBeTrue()
        ->and(DplyRuntime::expectsReverb())->toBeTrue()
        ->and(DplyRuntime::isSplitDeployment())->toBeFalse()
        ->and(DplyRuntime::configurationIssues())->toBe([]);
});

test('web mode does not run scheduler or expect horizon', function () {
    config([
        'dply_runtime.mode' => 'web',
        'queue.default' => 'redis',
    ]);

    expect(DplyRuntime::runsScheduler())->toBeFalse()
        ->and(DplyRuntime::expectsHorizon())->toBeFalse()
        ->and(DplyRuntime::expectsReverb())->toBeTrue()
        ->and(DplyRuntime::isSplitDeployment())->toBeTrue();
});

test('primary worker runs scheduler', function () {
    config([
        'dply_runtime.mode' => 'worker',
        'dply_runtime.worker_role' => 'primary',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
    ]);

    expect(DplyRuntime::runsScheduler())->toBeTrue()
        ->and(DplyRuntime::expectsHorizon())->toBeTrue()
        ->and(DplyRuntime::expectsReverb())->toBeFalse();
});

test('replica worker does not run scheduler', function () {
    config([
        'dply_runtime.mode' => 'worker',
        'dply_runtime.worker_role' => 'replica',
        'queue.default' => 'redis',
    ]);

    expect(DplyRuntime::runsScheduler())->toBeFalse()
        ->and(DplyRuntime::expectsHorizon())->toBeTrue();
});

test('split deployment flags missing redis queue connection', function () {
    config([
        'dply_runtime.mode' => 'worker',
        'dply_runtime.worker_role' => 'replica',
        'queue.default' => 'database',
    ]);

    expect(DplyRuntime::configurationIssues())->toContain(
        'QUEUE_CONNECTION must be redis when DPLY_RUNTIME is web or worker.',
    );
});

test('primary worker requires redis cache for onOneServer mutex', function () {
    config([
        'dply_runtime.mode' => 'worker',
        'dply_runtime.worker_role' => 'primary',
        'queue.default' => 'redis',
        'cache.default' => 'database',
    ]);

    expect(DplyRuntime::configurationIssues())->toContain(
        'CACHE_STORE=redis is required on the primary worker so Schedule::onOneServer() mutexes across deploys.',
    );
});

test('invalid runtime mode falls back to all', function () {
    config(['dply_runtime.mode' => 'invalid']);

    expect(DplyRuntime::mode())->toBe('all');
});
