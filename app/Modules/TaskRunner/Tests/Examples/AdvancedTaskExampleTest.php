<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Tests\Examples\AdvancedTaskExample;

// --- AdvancedTaskExample ---
test('AdvancedTaskExample::runBackupChain', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runBackupChain();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runWithConditionalStreaming', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runWithConditionalStreaming();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runWithProgressTracking', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runWithProgressTracking();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runWithWebSocketBroadcasting', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runWithWebSocketBroadcasting();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runWithMetricsTracking', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runWithMetricsTracking();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runParallelTasks', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runParallelTasks();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runWithCustomHandlers', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runWithCustomHandlers();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runWithFileStreaming', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runWithFileStreaming();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runWithConsoleStreaming', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runWithConsoleStreaming();
    expect(true)->toBeTrue();
});

test('AdvancedTaskExample::runCompleteExample', function () {
    test()->skip('Requires full Laravel context or external services.');
    AdvancedTaskExample::runCompleteExample();
    expect(true)->toBeTrue();
});
