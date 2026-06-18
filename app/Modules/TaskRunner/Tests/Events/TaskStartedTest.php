<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\Events\TaskStarted;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\TestTask;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskStarted Event', function () {
    describe('event construction', function () {
        it('creates event with required properties', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $context = ['user_id' => 1, 'environment' => 'production'];

            $event = new TaskStarted($task, $pendingTask, $context);

            expect($event->task)->toBe($task);
            expect($event->pendingTask)->toBe($pendingTask);
            expect($event->context)->toBe($context);
            expect($event->startedAt)->toBeString();
            expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3,6}Z$/');
        });

        it('creates event with empty context when not provided', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);

            $event = new TaskStarted($task, $pendingTask);

            expect($event->context)->toBe([]);
        });

        it('sets startedAt to current timestamp', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);

            $beforeEvent = now()->toISOString();
            $event = new TaskStarted($task, $pendingTask);
            $afterEvent = now()->toISOString();

            expect($event->startedAt)->toBeGreaterThanOrEqual($beforeEvent);
            expect($event->startedAt)->toBeLessThanOrEqual($afterEvent);
        });
    });

    describe('task information methods', function () {
        it('returns task name', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskName())->toBe('test-task');
        });

        it('returns task action', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskAction())->toBe('test');
        });

        it('returns task class', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskClass())->toBe(TestTask::class);
        });

        it('returns task timeout', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskTimeout())->toBe(300);
        });

        it('returns task data', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskData())->toBeArray();
        });

        it('returns task script', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskScript())->toBeString();
        });

        it('returns task view', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskView())->toBeString();
        });
    });

    describe('pending task information methods', function () {
        it('checks if task is running in background', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->isBackground())->toBeFalse();
        });

        it('returns connection name when running remotely', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            // Create a real Connection object
            $connection = new Connection(
                host: '127.0.0.1',
                port: 22,
                username: 'root',
                privateKey: "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBALeQw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\nAgMBAAECQQC1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n-----END RSA PRIVATE KEY-----",
                scriptPath: '/root/.dply-task-runner'
            );
            $pendingTask->connection = $connection;
            $pendingTask->connectionName = 'test-connection';
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getConnection())->toBeInstanceOf(Connection::class);
        });

        it('returns connection name string when running remotely', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $pendingTask->connectionName = 'test-connection';
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getConnectionName())->toBe('test-connection');
        });

        it('returns null connection when running locally', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getConnection())->toBeNull();
        });

        it('returns task ID', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $pendingTask->withId('task-123');
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskId())->toBe('task-123');
        });

        it('returns null task ID when not set', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->getTaskId())->toBeNull();
        });
    });

    describe('event serialization', function () {
        it('can be serialized and unserialized', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $context = ['test' => 'data'];
            $event = new TaskStarted($task, $pendingTask, $context);

            $serialized = serialize($event);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(TaskStarted::class);
            expect($unserialized->getTaskName())->toBe('test-task');
            expect($unserialized->context)->toBe($context);
        });
    });

    describe('event dispatchability', function () {
        it('can be dispatched', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event)->toBeInstanceOf(TaskStarted::class);
            expect(method_exists($event, 'dispatch'))->toBeTrue();
        });
    });

    describe('context data handling', function () {
        it('preserves complex context data', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $context = [
                'user' => ['id' => 1, 'name' => 'John'],
                'environment' => 'production',
                'tags' => ['deployment', 'backend'],
                'metadata' => [
                    'deployment_id' => 'deploy-123',
                    'commit_hash' => 'abc123',
                    'branch' => 'main',
                ],
            ];

            $event = new TaskStarted($task, $pendingTask, $context);

            expect($event->context)->toBe($context);
            expect($event->context['user']['name'])->toBe('John');
            expect($event->context['tags'])->toContain('deployment');
            expect($event->context['metadata']['deployment_id'])->toBe('deploy-123');
        });

        it('handles empty context', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask, []);

            expect($event->context)->toBe([]);
        });
    });

    describe('timestamp formatting', function () {
        it('uses ISO 8601 format', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3,6}Z$/');
        });

        it('is a valid date', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $event = new TaskStarted($task, $pendingTask);

            $date = new DateTime($event->startedAt);
            expect($date)->toBeInstanceOf(DateTime::class);
        });
    });
});
