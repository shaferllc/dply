<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Exceptions\MultiServerTaskException;

test('can create MultiServerTaskException with default constructor', function () {
    $exception = new MultiServerTaskException;

    expect($exception)->toBeInstanceOf(MultiServerTaskException::class)
        ->and($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('')
        ->and($exception->getCode())->toBe(0)
        ->and($exception->getMultiServerTaskId())->toBe('')
        ->and($exception->getConnections())->toBe([])
        ->and($exception->getFailedConnections())->toBe([]);
});

test('can create MultiServerTaskException with all parameters', function () {
    $message = 'Multi-server task failed';
    $taskId = 'task-123';
    $connections = ['server1', 'server2', 'server3'];
    $failedConnections = ['server2'];
    $code = 2001;

    $exception = new MultiServerTaskException($message, $taskId, $connections, $failedConnections, $code);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getMultiServerTaskId())->toBe($taskId)
        ->and($exception->getConnections())->toBe($connections)
        ->and($exception->getFailedConnections())->toBe($failedConnections)
        ->and($exception->getCode())->toBe($code);
});

test('getSuccessfulConnections returns connections not in failed list', function () {
    $connections = ['server1', 'server2', 'server3', 'server4'];
    $failedConnections = ['server2', 'server4'];

    $exception = new MultiServerTaskException('', '', $connections, $failedConnections);

    $successfulConnections = $exception->getSuccessfulConnections();
    expect($successfulConnections)->toContain('server1')
        ->and($successfulConnections)->toContain('server3')
        ->and($successfulConnections)->not->toContain('server2')
        ->and($successfulConnections)->not->toContain('server4')
        ->and(count($successfulConnections))->toBe(2);
});

test('getTotalServers returns correct count', function () {
    $connections = ['server1', 'server2', 'server3'];
    $exception = new MultiServerTaskException('', '', $connections);

    expect($exception->getTotalServers())->toBe(3);
});

test('getTotalServers returns zero for empty connections', function () {
    $exception = new MultiServerTaskException;

    expect($exception->getTotalServers())->toBe(0);
});

test('getFailedServers returns correct count', function () {
    $failedConnections = ['server1', 'server3'];
    $exception = new MultiServerTaskException('', '', [], $failedConnections);

    expect($exception->getFailedServers())->toBe(2);
});

test('getSuccessfulServers returns correct count', function () {
    $connections = ['server1', 'server2', 'server3', 'server4'];
    $failedConnections = ['server2'];

    $exception = new MultiServerTaskException('', '', $connections, $failedConnections);

    expect($exception->getSuccessfulServers())->toBe(3);
});

test('getSuccessRate returns correct percentage', function () {
    $connections = ['server1', 'server2', 'server3', 'server4'];
    $failedConnections = ['server2'];

    $exception = new MultiServerTaskException('', '', $connections, $failedConnections);

    expect($exception->getSuccessRate())->toBe(75.0);
});

test('getSuccessRate returns zero for empty connections', function () {
    $exception = new MultiServerTaskException;

    expect($exception->getSuccessRate())->toBe(0.0);
});

test('getSuccessRate returns 100 for all successful connections', function () {
    $connections = ['server1', 'server2', 'server3'];
    $failedConnections = [];

    $exception = new MultiServerTaskException('', '', $connections, $failedConnections);

    expect($exception->getSuccessRate())->toBe(100.0);
});

test('getSuccessRate returns 0 for all failed connections', function () {
    $connections = ['server1', 'server2', 'server3'];
    $failedConnections = ['server1', 'server2', 'server3'];

    $exception = new MultiServerTaskException('', '', $connections, $failedConnections);

    expect($exception->getSuccessRate())->toBe(0.0);
});

test('can create MultiServerTaskException with previous exception', function () {
    $previousException = new Exception('Previous multi-server error');
    $exception = new MultiServerTaskException('Multi-server failed', '', [], [], 0, $previousException);

    expect($exception->getPrevious())->toBe($previousException);
});

test('MultiServerTaskException has correct namespace', function () {
    $exception = new MultiServerTaskException;

    expect($exception)->toBeInstanceOf(MultiServerTaskException::class);
});

test('can access all properties correctly', function () {
    $message = 'Test multi-server exception';
    $taskId = 'test-task-456';
    $connections = ['prod-server', 'staging-server'];
    $failedConnections = ['staging-server'];

    $exception = new MultiServerTaskException($message, $taskId, $connections, $failedConnections);

    expect($exception->getMessage())->toBe($message)
        ->and($exception->getMultiServerTaskId())->toBe($taskId)
        ->and($exception->getConnections())->toBe($connections)
        ->and($exception->getFailedConnections())->toBe($failedConnections)
        ->and($exception->getSuccessfulConnections())->toBe(['prod-server'])
        ->and($exception->getTotalServers())->toBe(2)
        ->and($exception->getFailedServers())->toBe(1)
        ->and($exception->getSuccessfulServers())->toBe(1)
        ->and($exception->getSuccessRate())->toBe(50.0);
});
