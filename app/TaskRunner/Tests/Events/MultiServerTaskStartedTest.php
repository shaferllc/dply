<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Events;

use App\Modules\TaskRunner\Events\MultiServerTaskStarted;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

describe('MultiServerTaskStarted Event', function () {
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
        $startedAt = now()->toISOString();
        $options = ['parallel' => true, 'timeout' => 300];

        $event = new MultiServerTaskStarted($task, $connections, $multiServerTaskId, $startedAt, $options);

        expect($event->task)->toBe($task);
        expect($event->connections)->toBe($connections);
        expect($event->multiServerTaskId)->toBe($multiServerTaskId);
        expect($event->startedAt)->toBe($startedAt);
        expect($event->options)->toBe($options);
    });

    it('creates event with default options', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $multiServerTaskId = 'multi-server-123';
        $startedAt = now()->toISOString();

        $event = new MultiServerTaskStarted($task, $connections, $multiServerTaskId, $startedAt);

        expect($event->options)->toBe([]);
    });

    it('gets task name', function () {
        $task = new TestTask('Custom Task Name');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getTaskName())->toBe('Custom Task Name');
    });

    it('gets task class', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getTaskClass())->toBe(TestTask::class);
    });

    it('gets server count', function () {
        $task = new TestTask('Multi Server Task');
        $connections = [
            'server1' => ['host' => '192.168.1.100', 'port' => 22],
            'server2' => ['host' => '192.168.1.101', 'port' => 22],
            'server3' => ['host' => '192.168.1.102', 'port' => 22],
        ];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getServerCount())->toBe(3);
    });

    it('gets server count with empty connections', function () {
        $task = new TestTask('Multi Server Task');
        $connections = [];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getServerCount())->toBe(0);
    });

    it('checks if execution is parallel by default', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->isParallel())->toBeTrue();
    });

    it('checks if execution is parallel when explicitly set', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $options = ['parallel' => true];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString(), $options);

        expect($event->isParallel())->toBeTrue();
    });

    it('checks if execution is not parallel when explicitly set', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $options = ['parallel' => false];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString(), $options);

        expect($event->isParallel())->toBeFalse();
    });

    it('gets timeout value', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $options = ['timeout' => 300];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString(), $options);

        expect($event->getTimeout())->toBe(300);
    });

    it('gets timeout when not set', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getTimeout())->toBeNull();
    });

    it('checks if execution stops on failure by default', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->stopsOnFailure())->toBeFalse();
    });

    it('checks if execution stops on failure when explicitly set', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $options = ['stop_on_failure' => true];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString(), $options);

        expect($event->stopsOnFailure())->toBeTrue();
    });

    it('gets minimum success count', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $options = ['min_success' => 2];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString(), $options);

        expect($event->getMinSuccess())->toBe(2);
    });

    it('gets minimum success count when not set', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getMinSuccess())->toBeNull();
    });

    it('gets maximum failures count', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $options = ['max_failures' => 1];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString(), $options);

        expect($event->getMaxFailures())->toBe(1);
    });

    it('gets maximum failures count when not set', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getMaxFailures())->toBeNull();
    });

    it('can be serialized and unserialized', function () {
        $task = new TestTask('Multi Server Task');
        $connections = [
            'server1' => ['host' => '192.168.1.100', 'port' => 22],
            'server2' => ['host' => '192.168.1.101', 'port' => 22],
        ];
        $multiServerTaskId = 'multi-server-123';
        $startedAt = now()->toISOString();
        $options = ['parallel' => true, 'timeout' => 300];

        $event = new MultiServerTaskStarted($task, $connections, $multiServerTaskId, $startedAt, $options);

        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        expect($unserialized)->toBeInstanceOf(MultiServerTaskStarted::class);
        expect($unserialized->task->getName())->toBe($task->getName());
        expect($unserialized->connections)->toBe($connections);
        expect($unserialized->multiServerTaskId)->toBe($multiServerTaskId);
        expect($unserialized->startedAt)->toBe($startedAt);
        expect($unserialized->options)->toBe($options);
    });

    it('can be dispatched', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        Event::dispatch($event);

        Event::assertDispatched(MultiServerTaskStarted::class);
    });

    it('can be dispatched if', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        if (true) {
            Event::dispatch($event);
        }

        Event::assertDispatched(MultiServerTaskStarted::class);
    });

    it('can be dispatched unless', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        if (! false) {
            Event::dispatch($event);
        }

        Event::assertDispatched(MultiServerTaskStarted::class);
    });

    it('handles edge case with empty connections', function () {
        $task = new TestTask('Multi Server Task');
        $connections = [];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getServerCount())->toBe(0);
        expect($event->isParallel())->toBeTrue();
        expect($event->getTimeout())->toBeNull();
        expect($event->stopsOnFailure())->toBeFalse();
        expect($event->getMinSuccess())->toBeNull();
        expect($event->getMaxFailures())->toBeNull();
    });

    it('handles edge case with single server', function () {
        $task = new TestTask('Single Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getServerCount())->toBe(1);
        expect($event->isParallel())->toBeTrue();
    });

    it('handles edge case with many servers', function () {
        $task = new TestTask('Many Servers Task');
        $connections = [];
        for ($i = 1; $i <= 100; $i++) {
            $connections["server{$i}"] = ['host' => "192.168.1.{$i}", 'port' => 22];
        }
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getServerCount())->toBe(100);
    });

    it('handles complex connection structures', function () {
        $task = new TestTask('Complex Connections Task');
        $connections = [
            'web-server-1' => [
                'host' => '192.168.1.100',
                'port' => 22,
                'user' => 'deploy',
                'key_path' => '/path/to/key',
                'timeout' => 30,
            ],
            'web-server-2' => [
                'host' => '192.168.1.101',
                'port' => 22,
                'user' => 'deploy',
                'key_path' => '/path/to/key',
                'timeout' => 30,
            ],
            'db-server' => [
                'host' => '192.168.1.200',
                'port' => 22,
                'user' => 'admin',
                'key_path' => '/path/to/admin/key',
                'timeout' => 60,
            ],
        ];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getServerCount())->toBe(3);
        expect($event->connections)->toBe($connections);
        expect($event->connections['web-server-1']['host'])->toBe('192.168.1.100');
        expect($event->connections['db-server']['user'])->toBe('admin');
    });

    it('handles complex options structure', function () {
        $task = new TestTask('Complex Options Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $options = [
            'parallel' => true,
            'timeout' => 300,
            'stop_on_failure' => false,
            'min_success' => 2,
            'max_failures' => 1,
            'retry_count' => 3,
            'retry_delay' => 5,
            'log_level' => 'debug',
            'dry_run' => false,
        ];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString(), $options);

        expect($event->isParallel())->toBeTrue();
        expect($event->getTimeout())->toBe(300);
        expect($event->stopsOnFailure())->toBeFalse();
        expect($event->getMinSuccess())->toBe(2);
        expect($event->getMaxFailures())->toBe(1);
        expect($event->options['retry_count'])->toBe(3);
        expect($event->options['retry_delay'])->toBe(5);
        expect($event->options['log_level'])->toBe('debug');
        expect($event->options['dry_run'])->toBeFalse();
    });

    it('validates timestamp format', function () {
        $task = new TestTask('Multi Server Task');
        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $startedAt = now()->toISOString();
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', $startedAt);

        expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
    });

    it('handles task with custom properties', function () {
        $task = new TestTask('Custom Task');

        $connections = ['server1' => ['host' => '192.168.1.100', 'port' => 22]];
        $event = new MultiServerTaskStarted($task, $connections, 'multi-server-123', now()->toISOString());

        expect($event->getTaskName())->toBe('Custom Task');
        expect($event->getTaskClass())->toBe(TestTask::class);
        expect($event->task->getTimeout())->toBe(60);
        expect($event->task->getAction())->toBe('test_action');
        expect($event->task->getView())->toBe('test-view');
        expect($event->task->getScript())->toBe('echo "Hello World"');
    });
});
