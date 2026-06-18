<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Events;

use App\Modules\TaskRunner\Events\MultiServerTaskCompleted;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

describe('MultiServerTaskCompleted Event', function () {
    beforeEach(function () {
        Event::fake();
    });

    it('creates event with required properties', function () {
        $task = new TestTask('Multi Server Task');
        $connections = [
            'server1' => ['host' => '192.168.1.100', 'port' => 22],
            'server2' => ['host' => '192.168.1.101', 'port' => 22],
            'server3' => ['host' => '192.168.1.102', 'port' => 22],
        ];
        $multiServerTaskId = 'multi-server-123';
        $summary = [
            'total_servers' => 3,
            'successful_servers' => 3,
            'failed_servers' => 0,
            'success_rate' => 100.0,
            'duration' => 45.5,
            'results' => [
                'server1' => ['status' => 'success', 'output' => 'Task completed'],
                'server2' => ['status' => 'success', 'output' => 'Task completed'],
                'server3' => ['status' => 'success', 'output' => 'Task completed'],
            ],
        ];
        $startedAt = now()->toISOString();

        $event = new MultiServerTaskCompleted($task, $connections, $multiServerTaskId, $summary, $startedAt);

        expect($event->task)->toBe($task);
        expect($event->connections)->toBe($connections);
        expect($event->multiServerTaskId)->toBe($multiServerTaskId);
        expect($event->summary)->toBe($summary);
        expect($event->startedAt)->toBe($startedAt);
    });

    it('gets total servers from summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['total_servers' => 3];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getTotalServers())->toBe(3);
    });

    it('gets total servers when not in summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getTotalServers())->toBe(0);
    });

    it('gets successful servers from summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['successful_servers' => 2];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getSuccessfulServers())->toBe(2);
    });

    it('gets successful servers when not in summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getSuccessfulServers())->toBe(0);
    });

    it('gets failed servers from summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['failed_servers' => 1];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getFailedServers())->toBe(1);
    });

    it('gets failed servers when not in summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getFailedServers())->toBe(0);
    });

    it('gets success rate from summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['success_rate' => 75.5];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getSuccessRate())->toBe(75.5);
    });

    it('gets success rate when not in summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getSuccessRate())->toBe(0.0);
    });

    it('gets duration from summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['duration' => 120.5];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getDuration())->toBe(120.5);
    });

    it('gets duration when not in summary', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getDuration())->toBe(0.0);
    });

    it('gets duration for humans', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['duration' => 125.5];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getDurationForHumans())->toBe('2m 5s');
    });

    it('gets duration for humans with zero duration', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['duration' => 0.0];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getDurationForHumans())->toBe('0ms');
    });

    it('gets completed at timestamp', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $completedAt = '2023-12-01T10:30:00Z';
        $summary = ['completed_at' => $completedAt];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getCompletedAt())->toBe($completedAt);
    });

    it('checks if execution was successful', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['overall_success' => true];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->wasSuccessful())->toBeTrue();
    });

    it('checks if execution was not successful', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['overall_success' => false];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->wasSuccessful())->toBeFalse();
    });

    it('gets server results', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $results = [
            'server1' => ['status' => 'success', 'output' => 'Task completed'],
            'server2' => ['status' => 'failed', 'error' => 'Connection timeout'],
        ];
        $summary = ['results' => $results];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getResults())->toBe($results);
    });

    it('gets server results when not provided', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getResults())->toBe([]);
    });

    it('can be serialized and unserialized', function () {
        $task = new TestTask('Multi Server Task');
        $connections = [
            'server1' => ['host' => '192.168.1.100', 'port' => 22],
            'server2' => ['host' => '192.168.1.101', 'port' => 22],
        ];
        $multiServerTaskId = 'multi-server-123';
        $summary = [
            'total_servers' => 2,
            'successful_servers' => 2,
            'failed_servers' => 0,
            'success_rate' => 100.0,
            'duration' => 30.0,
            'overall_success' => true,
            'results' => [
                'server1' => ['status' => 'success'],
                'server2' => ['status' => 'success'],
            ],
        ];
        $startedAt = now()->toISOString();

        $event = new MultiServerTaskCompleted($task, $connections, $multiServerTaskId, $summary, $startedAt);
        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        expect($unserialized)->toBeInstanceOf(MultiServerTaskCompleted::class);
        expect($unserialized->multiServerTaskId)->toBe($multiServerTaskId);
        expect($unserialized->getTotalServers())->toBe(2);
        expect($unserialized->getSuccessfulServers())->toBe(2);
        expect($unserialized->getFailedServers())->toBe(0);
        expect($unserialized->getSuccessRate())->toBe(100.0);
        expect($unserialized->getDuration())->toBe(30.0);
        expect($unserialized->wasSuccessful())->toBeTrue();
    });

    it('can be dispatched', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['total_servers' => 1, 'successful_servers' => 1];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        Event::dispatch($event);

        Event::assertDispatched(MultiServerTaskCompleted::class);
    });

    it('can be dispatched if', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['total_servers' => 1, 'successful_servers' => 1];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        if (true) {
            Event::dispatch($event);
        }

        Event::assertDispatched(MultiServerTaskCompleted::class);
    });

    it('can be dispatched unless', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['total_servers' => 1, 'successful_servers' => 1];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        if (! false) {
            Event::dispatch($event);
        }

        Event::assertDispatched(MultiServerTaskCompleted::class);
    });

    it('handles edge case with empty connections', function () {
        $task = new TestTask('Multi Server Task');
        $connections = [];
        $summary = [
            'total_servers' => 0,
            'successful_servers' => 0,
            'failed_servers' => 0,
            'success_rate' => 0.0,
            'duration' => 0.0,
            'results' => [],
        ];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getTotalServers())->toBe(0);
        expect($event->getSuccessfulServers())->toBe(0);
        expect($event->getFailedServers())->toBe(0);
        expect($event->getSuccessRate())->toBe(0.0);
        expect($event->getDuration())->toBe(0.0);
        expect($event->getResults())->toBe([]);
        expect($event->wasSuccessful())->toBeFalse();
    });

    it('handles edge case with partial success', function () {
        $task = new TestTask('Multi Server Task');
        $connections = [
            'server1' => ['host' => '192.168.1.100', 'port' => 22],
            'server2' => ['host' => '192.168.1.101', 'port' => 22],
            'server3' => ['host' => '192.168.1.102', 'port' => 22],
        ];
        $summary = [
            'total_servers' => 3,
            'successful_servers' => 2,
            'failed_servers' => 1,
            'success_rate' => 66.67,
            'duration' => 45.5,
            'results' => [
                'server1' => ['status' => 'success'],
                'server2' => ['status' => 'success'],
                'server3' => ['status' => 'failed', 'error' => 'Connection timeout'],
            ],
        ];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getTotalServers())->toBe(3);
        expect($event->getSuccessfulServers())->toBe(2);
        expect($event->getFailedServers())->toBe(1);
        expect($event->getSuccessRate())->toBe(66.67);
        expect($event->getDuration())->toBe(45.5);
        expect($event->wasSuccessful())->toBeFalse();
    });

    it('handles edge case with very large numbers', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [
            'total_servers' => 999999,
            'successful_servers' => 999998,
            'failed_servers' => 1,
            'success_rate' => 99.9999,
            'duration' => 999999.999,
        ];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getTotalServers())->toBe(999999);
        expect($event->getSuccessfulServers())->toBe(999998);
        expect($event->getFailedServers())->toBe(1);
        expect($event->getSuccessRate())->toBe(99.9999);
        expect($event->getDuration())->toBe(999999.999);
    });

    it('handles floating point precision in success rate', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [
            'total_servers' => 3,
            'successful_servers' => 2,
            'failed_servers' => 1,
            'success_rate' => 66.66666666666667,
        ];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getSuccessRate())->toBe(66.66666666666667);
    });

    it('validates timestamp format', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = ['total_servers' => 1];
        $startedAt = '2023-12-01T10:30:00.000Z';

        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, $startedAt);

        expect($event->startedAt)->toBe($startedAt);
        expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
    });

    it('handles complex results structure', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $complexResults = [
            'server1' => [
                'status' => 'success',
                'output' => 'Task completed successfully',
                'exit_code' => 0,
                'execution_time' => 15.5,
                'memory_usage' => '256MB',
                'cpu_usage' => '25%',
                'logs' => [
                    'info' => ['Task started', 'Task completed'],
                    'error' => [],
                    'warning' => ['High memory usage detected'],
                ],
            ],
        ];
        $summary = ['results' => $complexResults];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, now()->toISOString());

        expect($event->getResults())->toBe($complexResults);
        expect($event->getResults()['server1']['status'])->toBe('success');
        expect($event->getResults()['server1']['exit_code'])->toBe(0);
        expect($event->getResults()['server1']['execution_time'])->toBe(15.5);
    });

    it('handles performance metrics with various data types', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $summary = [
            'total_servers' => 5,
            'successful_servers' => 4,
            'failed_servers' => 1,
            'success_rate' => 80.0,
            'duration' => 120.5,
            'completed_at' => '2023-12-01T10:35:00Z',
        ];
        $event = new MultiServerTaskCompleted($task, $connections, 'multi-server-123', $summary, '2023-12-01T10:30:00Z');

        $metrics = $event->getPerformanceMetrics();

        expect($metrics)->toBeArray();
        expect($metrics['total_servers'])->toBe(5);
        expect($metrics['successful_servers'])->toBe(4);
        expect($metrics['failed_servers'])->toBe(1);
        expect($metrics['success_rate'])->toBe(80.0);
        expect($metrics['duration'])->toBe(120.5);
        expect($metrics['duration_human'])->toBe('2m 0s');
        expect($metrics['started_at'])->toBe('2023-12-01T10:30:00Z');
        expect($metrics['completed_at'])->toBe('2023-12-01T10:35:00Z');
    });
});
